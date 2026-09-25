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
use App\Services\SupabaseStorageService;

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
     * ESP32 sends RFID uid or Fingerprint ID for authentication.
     *
     * Every rejected attempt - including ones the ESP32 decides locally (auth_method
     * "local_reject", e.g. a fingerprint with no matching template on the sensor) and
     * ones arriving during an active lockout - is written to access_logs as 'denied'
     * with a denial_reason, counts toward the brute-force lockout, and hands back an
     * access_log_id + camera_ip so the ESP32-CAM can photograph the attempt.
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
            'denial_reason'  => 'nullable|string|in:' . implode(',', AccessLog::DEVICE_DENIAL_REASONS),
        ]);

        $deviceId   = $validated['device_id'] ?? null;
        $clientKey  = $deviceId ? "dev_{$deviceId}" : "ip_" . md5($request->ip());
        $lockoutKey = "trilock_lockout_{$clientKey}";
        $failKey    = "trilock_failed_{$clientKey}";

        // Update device heartbeat whenever hardware checks in
        if ($deviceId) {
            $this->touchDevice($deviceId, $request->ip());
        }

        // Current LAN IP of the ESP32-CAM (from its own heartbeat), handed back to the main
        // controller so it can trigger a capture with a direct Wi-Fi call instead of relying
        // on a physical UART wire between the two boards.
        $cameraIp = Device::where('device_type', 'esp32_cam')
            ->whereNotNull('ip_address')
            ->orderByDesc('last_seen')
            ->value('ip_address');

        // Check if currently locked out. The attempt is still recorded (and photographed)
        // - a burst of tries during a lockout is exactly what an admin needs to see.
        $lockoutUntil = Cache::get($lockoutKey);
        if ($lockoutUntil && now()->timestamp < $lockoutUntil) {
            $remaining = $lockoutUntil - now()->timestamp;
            $log = $this->logDeniedAttempt(null, 'lockout_active', $deviceId);

            return response()->json([
                'status'          => 'locked_out',
                'message'         => "Security Lockout: Too many invalid attempts. Try again in {$remaining} seconds.",
                'reason'          => 'lockout_active',
                'lockout_seconds' => $remaining,
                'access_log_id'   => $log->id,
                'camera_ip'       => $cameraIp,
            ], 429);
        }

        $settings     = Setting::getSettings();
        $user         = null;
        $denialReason = null;
        $authMethod   = $validated['auth_method'];
        $cardUid      = !empty($validated['card_uid']) ? strtoupper($validated['card_uid']) : null;
        $fpId         = !empty($validated['fingerprint_id']) ? (int) $validated['fingerprint_id'] : null;
        $pinCode      = !empty($validated['pin_code']) ? $validated['pin_code'] : null;

        // 1. Triple Multi-Factor Authentication: RFID + Fingerprint + PIN required
        if (in_array($authMethod, ['multi_factor', 'triple_auth', 'all_three', 'rfid_fingerprint_pin'], true)) {
            $authMethod = 'multi_factor';

            if (!$cardUid || !$fpId || !$pinCode) {
                $denialReason = 'missing_factors';
            } else {
                $rfid = RfidCard::with('user')->where('card_uid', $cardUid)->where('status', 'active')->first();
                $fp   = Fingerprint::where('fingerprint_id', $fpId)->where('status', 'active')->first();

                // Attribute the attempt to the card's owner (if any) so a stolen/borrowed
                // card shows up against the right person in the audit trail.
                $user = $rfid?->user;

                if (!$user) {
                    $denialReason = 'rfid_not_recognized';
                } elseif (!$fp) {
                    $denialReason = 'fingerprint_not_recognized';
                } elseif ($fp->user_id !== $rfid->user_id) {
                    $denialReason = 'credential_mismatch';
                } elseif (!$this->pinMatchesUser($pinCode, $rfid->user_id)) {
                    $denialReason = 'pin_incorrect';
                } elseif ($user->status !== 'active') {
                    $denialReason = 'user_inactive';
                }
            }
        } elseif ($authMethod === 'local_reject') {
            // The ESP32 already rejected this on-device (nothing it could send us to verify)
            // - record it, count it toward the lockout, and let the camera capture it.
            $denialReason = $validated['denial_reason'] ?? 'fingerprint_not_recognized';
            if ($cardUid) {
                $user = RfidCard::with('user')->where('card_uid', $cardUid)->where('status', 'active')->first()?->user;
            }
        } elseif ($authMethod === 'rfid') {
            $rfid = $cardUid ? RfidCard::with('user')->where('card_uid', $cardUid)->where('status', 'active')->first() : null;
            $user = $rfid?->user;
            $denialReason = !$user ? 'rfid_not_recognized' : ($user->status !== 'active' ? 'user_inactive' : null);
        } elseif ($authMethod === 'fingerprint') {
            $fp = $fpId ? Fingerprint::with('user')->where('fingerprint_id', $fpId)->where('status', 'active')->first() : null;
            $user = $fp?->user;
            $denialReason = !$user ? 'fingerprint_not_recognized' : ($user->status !== 'active' ? 'user_inactive' : null);
        } elseif ($authMethod === 'pin') {
            if ($pinCode) {
                foreach (AccessPin::where('status', 'active')->with('user')->get() as $pin) {
                    if (Hash::check($pinCode, $pin->pin_code)) {
                        $user = $pin->user;
                        break;
                    }
                }
            }
            $denialReason = !$user ? 'pin_incorrect' : ($user->status !== 'active' ? 'user_inactive' : null);
        } elseif ($authMethod === 'rfid_fingerprint') {
            $rfid = $cardUid ? RfidCard::with('user')->where('card_uid', $cardUid)->where('status', 'active')->first() : null;
            $fp   = $fpId ? Fingerprint::where('fingerprint_id', $fpId)->where('status', 'active')->first() : null;
            $user = $rfid?->user;
            if (!$user) {
                $denialReason = 'rfid_not_recognized';
            } elseif (!$fp) {
                $denialReason = 'fingerprint_not_recognized';
            } elseif ($fp->user_id !== $rfid->user_id) {
                $denialReason = 'credential_mismatch';
            } elseif ($user->status !== 'active') {
                $denialReason = 'user_inactive';
            }
        } else {
            $denialReason = 'missing_factors';
        }

        if ($denialReason === null) {
            $log = AccessLog::create([
                'user_id'               => $user->id,
                'authentication_method' => $authMethod,
                'access_type'           => 'entry',
                'captured_image'        => null,
                'door_status'           => 'unlocked',
                'date'                  => now()->toDateString(),
                'time'                  => now()->toTimeString(),
                'device_id'             => $deviceId,
            ]);

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
                'camera_ip'      => $cameraIp,
            ]);
        }

        $log         = $this->logDeniedAttempt($user, $denialReason, $deviceId);
        $reasonLabel = AccessLog::DENIAL_REASONS[$denialReason] ?? $denialReason;

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
                'remarks'   => "SECURITY ALERT: Excessive failed access attempts ({$currentFails}) from {$clientKey}. Last reason: {$reasonLabel}. Lockout activated for {$lockoutSec}s.",
            ]);

            Log::warning("TRILOCK: Security lockout triggered for {$clientKey} after {$currentFails} consecutive failures (last: {$denialReason}).");

            return response()->json([
                'status'          => 'locked_out',
                'message'         => "Security Lockout: Too many invalid attempts. Device locked for {$lockoutSec} seconds.",
                'reason'          => $denialReason,
                'lockout_seconds' => $lockoutSec,
                'access_log_id'   => $log->id,
                'camera_ip'       => $cameraIp,
            ], 429);
        }

        DoorLog::create([
            'status'    => 'locked',
            'opened_at' => null,
            'closed_at' => null,
            'locked_at' => now(),
            'remarks'   => "ESP32 API: ACCESS DENIED - {$reasonLabel} via {$authMethod} (Attempt {$currentFails}/{$maxFails})",
        ]);

        return response()->json([
            'status'        => 'denied',
            'message'       => "Access Denied: {$reasonLabel} (Attempt {$currentFails}/{$maxFails})",
            'reason'        => $denialReason,
            'access_log_id' => $log->id,
            'attempts_left' => max(0, $maxFails - $currentFails),
            'camera_ip'     => $cameraIp,
        ], 403);
    }

    /**
     * POST /api/access/offline-log
     * Backfills a door unlock that the ESP32 main controller already granted locally
     * (matched against its cached RFID+fingerprint+PIN combo) while the server was
     * unreachable. The access decision was already made on-device at the same security
     * bar as the online path - this endpoint only records it so it appears on the
     * dashboard; it never re-authorizes. No image: the capture window is real-time only,
     * and by the time this backfills there's no one left at the door to photograph.
     */
    public function logOfflineAccess(Request $request)
    {
        $validated = $request->validate([
            'device_id'      => 'required|integer',
            'user_id'        => 'required|integer|exists:users,id',
            'card_uid'       => 'nullable|string',
            'fingerprint_id' => 'nullable|integer',
        ]);

        $user = User::find($validated['user_id']);

        $this->touchDevice($validated['device_id'], $request->ip());

        $log = AccessLog::create([
            'user_id'               => $user->id,
            'authentication_method' => 'multi_factor_offline',
            'access_type'           => 'entry',
            'captured_image'        => null,
            'door_status'           => 'unlocked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
            'device_id'             => $validated['device_id'],
        ]);

        DoorLog::create([
            'status'    => 'unlocked',
            'opened_at' => now(),
            'closed_at' => null,
            'locked_at' => null,
            'remarks'   => "ESP32 API: Backfilled OFFLINE multi_factor access for {$user->full_name} " .
                            "(granted on-device from cached credentials while server was unreachable; no photo captured)",
        ]);

        return response()->json([
            'status'        => 'success',
            'message'       => 'Offline access event logged',
            'access_log_id' => $log->id,
        ]);
    }

    /**
     * POST /api/upload-image
     * ESP32-CAM uploads captured image JPG file
     */
    public function uploadImage(Request $request, SupabaseStorageService $supabaseStorage)
    {
        $request->validate([
            'image'         => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'access_log_id' => 'nullable|integer',
            'device_id'     => 'nullable|integer',
        ]);

        $imageFile = $request->file('image');
        $path = $supabaseStorage->upload($imageFile);

        // Prefer the device_id the ESP32-CAM sent explicitly (same pattern as every other
        // hardware endpoint); fall back to a per-device token match if the CAM didn't send one.
        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            $device = $request->attributes->get('authenticated_device');
            $deviceId = $device ? $device->id : null;
        }
        // No IP here: $request->ip() is the camera's public/NAT (or proxy) address, and
        // overwriting the LAN IP from its heartbeat would hand the main controller an
        // unreachable camera_ip - every capture after the first would silently fail.
        if ($deviceId) {
            $this->touchDevice($deviceId);
        }

        $logId = $request->input('access_log_id');
        $log = null;

        if (!empty($logId)) {
            $log = AccessLog::find($logId);
            if ($log) {
                $log->update(['captured_image' => $path]);
            }
        }

        // If no access log was linked (or access_log_id was 0/unmatched), create a standalone capture log
        // so the photo immediately appears in Camera Monitoring (/camera), Access Logs (/logs), and Dashboard
        if (!$log) {
            $log = AccessLog::create([
                'user_id'               => null,
                'authentication_method' => 'unauthorized',
                'access_type'           => 'denied',
                'denial_reason'         => 'unidentified_capture',
                'captured_image'        => $path,
                'door_status'           => 'locked',
                'date'                  => now()->toDateString(),
                'time'                  => now()->toTimeString(),
                'device_id'             => $deviceId,
            ]);
        }

        return response()->json([
            'status'        => 'success',
            'message'       => 'Image uploaded successfully',
            'image_path'    => $path,
            'access_log_id' => $log->id,
        ]);
    }

    /**
     * POST /api/door-status
     * ESP32 reports door status changes (locked, unlocked, opened, closed, left_open)
     */
    public function updateDoorStatus(Request $request)
    {
        // Was 'in:opened,closed' only - silently 422'd both 'locked' (solenoid
        // re-engaging, see ESP32 lockDoor()) and 'left_open' (ajar alarm, see
        // checkDoorAjar()) despite this endpoint's own docblock promising all five.
        $validated = $request->validate([
            'status'    => 'required|string|in:opened,closed,locked,left_open',
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
            'locked_at' => $validated['status'] === 'locked' ? now() : null,
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
            'status'                  => 'ok',
            'device'                  => $device ? $device->device_name : null,
            'pending_command'         => $pendingCmd['command'] ?? null,
            'duration'                => $pendingCmd['duration'] ?? null,
            'deleted_fingerprint_ids' => $pendingCmd['deleted_fingerprint_ids'] ?? [],
            'time'                    => now()->toDateTimeString(),
        ]);
    }

    /**
     * GET /api/offline-sync
     * Periodically pulled by the ESP32 and cached to local flash (LittleFS) so it can
     * still verify a full RFID+fingerprint+PIN combo when the server is unreachable.
     * Only users with all three credentials active are included — offline mode still
     * requires all three factors, so a partial record could never match anyway.
     * Also returns active_fingerprint_ids so ESP32 can purge deleted/unassigned templates.
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

        $activeFingerprintIds = Fingerprint::where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->pluck('fingerprint_id')
            ->all();

        return response()->json([
            'status'                 => 'success',
            'synced_at'              => now()->toIso8601String(),
            'active_fingerprint_ids' => $activeFingerprintIds,
            'data'                   => $entries,
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

            // Door state only comes from the main controller - while it's offline the latest
            // door log is just the last thing it reported, not the door's real state.
            $doorLive = $devices->where('type', 'esp32_main')->contains('status', 'online');

            return [
                'door_status'    => $latestDoorLog ? $latestDoorLog->status : 'locked',
                'door_live'      => $doorLive,
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
     * Record a rejected attempt as a 'denied' access log. Also invalidates the cached
     * dashboard status so the "last access" widget reflects it immediately.
     */
    private function logDeniedAttempt(?User $user, string $reason, ?int $deviceId): AccessLog
    {
        $log = AccessLog::create([
            'user_id'               => $user?->id,
            'authentication_method' => 'unauthorized',
            'access_type'           => 'denied',
            'denial_reason'         => $reason,
            'captured_image'        => null,
            'door_status'           => 'locked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
            'device_id'             => $deviceId,
        ]);

        Cache::forget('trilock_dashboard_status');

        return $log;
    }

    /**
     * Checks a PIN against the given user's own active PIN(s) only - not every PIN in
     * the system - so two users sharing a PIN can't cross-match each other's card.
     */
    private function pinMatchesUser(string $pinCode, int $userId): bool
    {
        foreach (AccessPin::where('user_id', $userId)->where('status', 'active')->get() as $pin) {
            if (Hash::check($pinCode, $pin->pin_code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a device as online and update its last_seen timestamp.
     * Accepts an optional IP address to keep the devices table current.
     */
    private function touchDevice(int $deviceId, ?string $ip = null): ?Device
    {
        $device = Device::find($deviceId);
        if (!$device) return null;

        if ($device->markSeen($ip)) {
            Cache::forget('trilock_dashboard_status');
        }

        return $device;
    }
}
