<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\RfidCard;
use App\Models\Fingerprint;
use App\Models\AccessPin;
use App\Models\AccessLog;
use App\Models\DoorLog;
use App\Models\Setting;
use App\Models\Device;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HardwareApiController extends Controller
{
    /**
     * GET /api/settings
     * Retrieve hardware config for ESP32 devices
     */
    public function getSettings()
    {
        $settings = Setting::getSettings();

        return response()->json([
            'status' => 'success',
            'data' => [
                'door_unlock_duration' => $settings->door_unlock_duration,
                'auto_lock' => (bool)$settings->auto_lock,
                'camera_enabled' => (bool)$settings->camera_enabled,
                'capture_entry' => (bool)$settings->capture_entry,
                'buzzer_enabled' => (bool)$settings->buzzer_enabled,
                'lab_name' => $settings->lab_name,
            ]
        ]);
    }

    /**
     * POST /api/access
     * ESP32 sends RFID uid or Fingerprint ID for authentication
     */
    public function processAccess(Request $request)
    {
        $validated = $request->validate([
            'auth_method'    => 'required|string',
            'access_type'    => 'nullable|string',
            'card_uid'       => 'nullable|string',
            'fingerprint_id' => 'nullable|integer',
            'pin_code'       => 'nullable|string',
            'device_id'      => 'nullable|integer',
        ]);

        $deviceId   = $validated['device_id'] ?? null;
        $clientKey  = $deviceId ? "dev_{$deviceId}" : "ip_" . md5($request->ip());
        $lockoutKey = "trilock_lockout_{$clientKey}";
        $failKey    = "trilock_failed_{$clientKey}";

        // Check if currently locked out
        $lockoutUntil = Cache::get($lockoutKey);
        if ($lockoutUntil && now()->timestamp < $lockoutUntil) {
            $remaining = $lockoutUntil - now()->timestamp;
            return response()->json([
                'status'          => 'locked_out',
                'message'         => "Security Lockout: Too many invalid attempts. Try again in {$remaining} seconds.",
                'lockout_seconds' => $remaining,
            ], 429);
        }

        $settings   = Setting::getSettings();
        $user       = null;
        $authorized = false;
        $authMethod = $validated['auth_method'];

        // 1. Triple Multi-Factor Authentication: RFID + Fingerprint + PIN required
        if ($authMethod === 'multi_factor' || $authMethod === 'triple_auth' || $authMethod === 'all_three' || $authMethod === 'rfid_fingerprint_pin') {
            $cardUid = !empty($validated['card_uid']) ? strtoupper($validated['card_uid']) : null;
            $fpId    = !empty($validated['fingerprint_id']) ? (int)$validated['fingerprint_id'] : null;
            $pinCode = !empty($validated['pin_code']) ? $validated['pin_code'] : null;

            if ($cardUid && $fpId && $pinCode) {
                $rfid = RfidCard::where('card_uid', $cardUid)->where('status', 'active')->first();
                $fp   = Fingerprint::where('fingerprint_id', $fpId)->where('status', 'active')->first();
                
                $pinUser = null;
                $pins = AccessPin::where('status', 'active')->with('user')->get();
                foreach ($pins as $pin) {
                    if (Hash::check($pinCode, $pin->pin_code)) {
                        $pinUser = $pin->user;
                        break;
                    }
                }

                // All 3 factors must match the same active user
                if ($rfid && $fp && $pinUser &&
                    $rfid->user_id === $fp->user_id &&
                    $rfid->user_id === $pinUser->id &&
                    $rfid->user && $rfid->user->status === 'active') {
                    $user = $rfid->user;
                    $authorized = true;
                    $authMethod = 'multi_factor';
                }
            }
        } elseif ($authMethod === 'rfid' && !empty($validated['card_uid'])) {
            $rfid = RfidCard::where('card_uid', strtoupper($validated['card_uid']))
                ->where('status', 'active')
                ->first();
            if ($rfid && $rfid->user) {
                $user = $rfid->user;
                if ($user->status === 'active') {
                    $authorized = true;
                }
            }
        } elseif ($authMethod === 'fingerprint' && !empty($validated['fingerprint_id'])) {
            $fp = Fingerprint::where('fingerprint_id', $validated['fingerprint_id'])
                ->where('status', 'active')
                ->first();
            if ($fp && $fp->user) {
                $user = $fp->user;
                if ($user->status === 'active') {
                    $authorized = true;
                }
            }
        } elseif ($authMethod === 'pin' && !empty($validated['pin_code'])) {
            $pins = AccessPin::where('status', 'active')->with('user')->get();
            foreach ($pins as $pin) {
                if (Hash::check($validated['pin_code'], $pin->pin_code)) {
                    if ($pin->user) {
                        $user = $pin->user;
                        if ($user->status === 'active') {
                            $authorized = true;
                        }
                    }
                    break;
                }
            }
        } elseif ($authMethod === 'rfid_fingerprint') {
            $rfid = RfidCard::where('card_uid', strtoupper($validated['card_uid']))
                ->where('status', 'active')
                ->first();
            $fp = Fingerprint::where('fingerprint_id', $validated['fingerprint_id'])
                ->where('status', 'active')
                ->first();
            if ($rfid && $fp && $rfid->user_id === $fp->user_id && $rfid->user) {
                $user = $rfid->user;
                if ($user->status === 'active') {
                    $authorized = true;
                }
            }
        }

        // Update device heartbeat whenever hardware checks in
        $deviceId = $validated['device_id'] ?? null;
        if ($deviceId) {
            $this->touchDevice($deviceId, $request->ip());
        }

        $log = AccessLog::create([
            'user_id'               => $user ? $user->id : null,
            'authentication_method' => $authorized ? $authMethod : 'unauthorized',
            'access_type'           => $authorized ? 'entry' : 'denied',
            'captured_image'        => null,
            'door_status'           => $authorized ? 'unlocked' : 'locked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
            'device_id'             => $deviceId,
        ]);

        if ($authorized) {
            // Clear failed attempts counter upon successful authorized access
            Cache::forget($failKey);

            // Create an unlocked door log; the actual close/lock timestamps will be
            // updated by door-status events (via /api/door-status), not guessed.
            DoorLog::create([
                'status'    => 'unlocked',
                'opened_at' => now(),
                'closed_at' => null,
                'locked_at' => null,
                'remarks'   => "ESP32 API: Verified {$authMethod} access for {$user->full_name}",
            ]);

            return response()->json([
                'status'         => 'granted',
                'message'        => 'Access Granted',
                'user'           => [
                    'id'   => $user->id,
                    'name' => $user->full_name,
                    'role' => $user->role,
                ],
                'unlock_duration' => $settings->door_unlock_duration,
                'access_log_id'  => $log->id,
            ]);
        }

        // Increment failed attempts counter (TTL 2 minutes)
        $maxFails = config('trilock.max_failed_attempts', 5);
        $lockoutSec = config('trilock.lockout_duration', 60);

        $currentFails = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $currentFails, 120);

        if ($currentFails >= $maxFails) {
            $lockoutTimestamp = now()->addSeconds($lockoutSec)->timestamp;
            Cache::put($lockoutKey, $lockoutTimestamp, $lockoutSec);
            Cache::forget($failKey);

            DoorLog::create([
                'status'    => 'locked',
                'opened_at' => null,
                'closed_at' => null,
                'locked_at' => now(),
                'remarks'   => "SECURITY ALERT: Excessive failed access attempts ({$currentFails}) from {$clientKey}. Lockout activated for {$lockoutSec}s.",
            ]);

            Log::warning("TRILOCK: Security lockout triggered for {$clientKey} after {$currentFails} consecutive failures.");

            return response()->json([
                'status'          => 'locked_out',
                'message'         => "Security Lockout: Too many invalid attempts. Device locked for {$lockoutSec} seconds.",
                'lockout_seconds' => $lockoutSec,
                'access_log_id'   => $log->id,
            ], 429);
        }

        DoorLog::create([
            'status'    => 'locked',
            'opened_at' => null,
            'closed_at' => null,
            'locked_at' => now(),
            'remarks'   => "ESP32 API: ACCESS DENIED - Invalid {$authMethod} credential (Attempt {$currentFails}/{$maxFails})",
        ]);

        return response()->json([
            'status'        => 'denied',
            'message'       => "Access Denied: Invalid or inactive credential (Attempt {$currentFails}/{$maxFails})",
            'access_log_id' => $log->id,
            'attempts_left' => max(0, $maxFails - $currentFails),
        ], 403);
    }

    /**
     * POST /api/upload-image
     * ESP32-CAM uploads captured image JPG file
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image'         => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'access_log_id' => 'nullable|exists:access_logs,id',
        ]);

        $path = $request->file('image')->store('captures');

        if ($request->filled('access_log_id')) {
            $log = AccessLog::find($request->access_log_id);
            if ($log) {
                $log->update(['captured_image' => $path]);
            }
        }

        return response()->json([
            'status'     => 'success',
            'message'    => 'Image uploaded successfully',
            'image_path' => $path,
        ]);
    }

    /**
     * POST /api/door-status
     * ESP32 reports door status changes (locked, unlocked, opened, closed, left_open)
     */
    public function updateDoorStatus(Request $request)
    {
        $validated = $request->validate([
            'status'    => 'required|string|in:opened,closed',
            'remarks'   => 'nullable|string',
            'device_id' => 'nullable|integer',
        ]);

        // Update device heartbeat on every sensor event
        $deviceId = $validated['device_id'] ?? null;
        if ($deviceId) {
            $this->touchDevice($deviceId, $request->ip());
        }

        $doorLog = DoorLog::create([
            'status'    => $validated['status'],
            'opened_at' => $validated['status'] === 'opened' ? now() : null,
            'closed_at' => $validated['status'] === 'closed' ? now() : null,
            'remarks'   => $validated['remarks'] ?? "ESP32 door status event: {$validated['status']}",
        ]);

        return response()->json([
            'status'      => 'success',
            'message'     => 'Door status logged',
            'door_log_id' => $doorLog->id,
        ]);
    }

    /**
     * POST /api/heartbeat
     * ESP32 sends a keep-alive ping every 30 seconds
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'device_id'  => 'required|integer',
            'ip_address' => 'nullable|string|max:45',
        ]);

        $device = $this->touchDevice($validated['device_id'], $validated['ip_address'] ?? $request->ip());

        // Keyed per device — a global key would let any device's heartbeat consume a
        // command meant for a different door once more than one controller is registered.
        $pendingCmd = Cache::pull("trilock_pending_command_{$validated['device_id']}");

        return response()->json([
            'status'          => 'ok',
            'device'          => $device ? $device->device_name : null,
            'pending_command' => $pendingCmd['command'] ?? null,
            'duration'        => $pendingCmd['duration'] ?? null,
            'time'            => now()->toDateTimeString(),
        ]);
    }

    /**
     * GET /api/offline-sync
     * Periodically pulled by the ESP32 and cached to local flash (LittleFS) so it can
     * still verify a full RFID+fingerprint+PIN combo when the server is unreachable.
     * Only users with all three credentials active are included — offline mode still
     * requires all three factors, so a partial record could never match anyway.
     */
    public function offlineSync()
    {
        $entries = User::where('status', 'active')
            ->with(['activeRfidCard', 'activeFingerprint', 'activePin'])
            ->get()
            ->filter(fn ($user) => $user->activeRfidCard && $user->activeFingerprint && $user->activePin?->offline_hash)
            ->map(fn ($user) => [
                'user_id'          => $user->id,
                'card_uid'         => $user->activeRfidCard->card_uid,
                'fingerprint_id'   => $user->activeFingerprint->fingerprint_id,
                'pin_offline_hash' => $user->activePin->offline_hash,
            ])
            ->values();

        return response()->json([
            'status'    => 'success',
            'synced_at' => now()->toIso8601String(),
            'data'      => $entries,
        ]);
    }

    /**
     * GET /api/status
     * Lightweight JSON endpoint polled by the dashboard.
     * Cached for 3 seconds to ensure low latency and zero DB hammering during frequent polling.
     */
    public function getStatus()
    {
        $data = Cache::remember('trilock_dashboard_status', 3, function () {
            $latestDoorLog = DoorLog::latest()->first();
            $devices = Device::select('id', 'device_name', 'device_type', 'ip_address', 'status', 'last_seen')
                ->get()
                ->map(function ($device) {
                    $isOnline = $device->isOnline();

                    return [
                        'id'          => $device->id,
                        'name'        => $device->device_name,
                        'type'        => $device->device_type,
                        'ip_address'  => $device->ip_address,
                        'status'      => $isOnline ? 'online' : 'offline',
                        'last_seen'   => $device->last_seen,
                    ];
                });

            $recentLog = AccessLog::with('user')
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'door_status'    => $latestDoorLog ? $latestDoorLog->status : 'locked',
                'door_updated'   => $latestDoorLog ? $latestDoorLog->updated_at : null,
                'devices'        => $devices,
                'last_access'    => $recentLog ? [
                    'user'        => $recentLog->user ? $recentLog->user->full_name : 'Unauthorized',
                    'access_type' => $recentLog->access_type,
                    'time'        => $recentLog->time,
                ] : null,
            ];
        });

        return response()->json(array_merge($data, [
            'server_time' => now()->toDateTimeString(),
        ]));
    }

    // -------------------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------------------

    /**
     * Mark a device as online and update its last_seen timestamp.
     * Accepts an optional IP address to keep the devices table current.
     */
    private function touchDevice(int $deviceId, ?string $ip = null): ?Device
    {
        $device = Device::find($deviceId);
        if (!$device) return null;

        $data = ['status' => 'online', 'last_seen' => now()];
        if ($ip) $data['ip_address'] = $ip;

        $device->update($data);
        Cache::forget('trilock_dashboard_status');
        return $device->fresh();
    }
}
