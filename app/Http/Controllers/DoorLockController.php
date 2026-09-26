<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DoorLog;
use App\Models\Setting;
use App\Models\AccessLog;
use App\Models\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\DoorState;

class DoorLockController extends Controller
{
    public function index()
    {
        $latestDoorLog = DoorLog::latest()->first();
        $doorLogs      = DoorLog::orderBy('created_at', 'desc')->paginate(15);
        $settings      = Setting::getSettings();

        $unlocksToday = DoorLog::whereDate('created_at', today())
            ->where('status', 'unlocked')
            ->count();

        $devicesTotal = Device::count();
        $mainControllers = Device::where('device_type', 'esp32_main')->orderBy('device_name')->get();

        return view('door.index', compact(
            'latestDoorLog',
            'doorLogs',
            'settings',
            'unlocksToday',
            'devicesTotal',
            'mainControllers'
        ));
    }

    public function unlock(Request $request)
    {
        $user = Auth::user();

        $mainControllers = Device::where('device_type', 'esp32_main')->orderBy('device_name')->get();

        // With more than one main controller registered, the admin must say which door —
        // silently picking one (the old behaviour) would unlock the wrong one.
        $validated = $request->validate([
            'device_id' => $mainControllers->count() > 1
                ? 'required|exists:devices,id'
                : 'nullable|exists:devices,id',
        ]);

        $device = $mainControllers->count() === 1
            ? $mainControllers->first()
            : $mainControllers->firstWhere('id', (int) ($validated['device_id'] ?? 0));

        if ($mainControllers->isNotEmpty() && !$device) {
            return back()->with('error', 'Select which registered device to unlock.');
        }

        // Record the admin-initiated remote unlock action
        DoorLog::create([
            'status'    => 'unlocked',
            'opened_at' => now(),
            'closed_at' => null,
            'locked_at' => null,
            'remarks'   => $device
                ? "Remote unlock requested by Admin {$user->name} ({$device->device_name})"
                : "Remote unlock requested by Admin {$user->name}",
        ]);

        AccessLog::create([
            'user_id'               => $user->id,
            'authentication_method' => 'manual_remote',
            'access_type'           => 'entry',
            'captured_image'        => null,
            'door_status'           => 'unlocked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
            'device_id'             => $device?->id,
        ]);

        // Signal the physical ESP32 hardware to fire the solenoid.
        // The ESP32 main controller runs a tiny HTTP server on port 8080.
        $signalResult = $this->signalEsp32Unlock($device);

        if ($signalResult['success'] && empty($signalResult['queued'])) {
            return back()->with('success', 'Door unlocked remotely. It relocks automatically once the door is opened and closed again.');
        }
        if ($signalResult['success']) {
            // Not confirmed yet - the lock picks it up on its next check-in
            return back()->with('success', 'Unlock sent. The lock picks it up on its next check-in (within ~30s); the status updates when it does.');
        }

        // Still logged — but warn the admin the hardware may not have responded
        return back()->with('warning', "Unlock logged, but could not reach the ESP32 hardware: {$signalResult['error']}. Verify the device is online.");
    }

    public function lock(Request $request)
    {
        $user = Auth::user();

        $mainControllers = Device::where('device_type', 'esp32_main')->orderBy('device_name')->get();

        $validated = $request->validate([
            'device_id' => $mainControllers->count() > 1
                ? 'required|exists:devices,id'
                : 'nullable|exists:devices,id',
        ]);

        $device = $mainControllers->count() === 1
            ? $mainControllers->first()
            : $mainControllers->firstWhere('id', (int) ($validated['device_id'] ?? 0));

        if ($mainControllers->isNotEmpty() && !$device) {
            return back()->with('error', 'Select which registered device to lock.');
        }

        DoorLog::create([
            'status'    => 'locked',
            'opened_at' => null,
            'closed_at' => null,
            'locked_at' => now(),
            'remarks'   => $device
                ? "Remote lock requested by Admin {$user->name} ({$device->device_name})"
                : "Remote lock requested by Admin {$user->name}",
        ]);

        $signalResult = $this->signalEsp32Lock($device);

        if ($signalResult['success'] && empty($signalResult['queued'])) {
            return back()->with('success', 'Door locked remotely.');
        }
        if ($signalResult['success']) {
            return back()->with('success', 'Lock sent. The lock picks it up on its next check-in (within ~30s); the status updates when it does.');
        }

        return back()->with('warning', "Lock logged, but could not reach the ESP32 hardware: {$signalResult['error']}. Verify the device is online.");
    }

    // -------------------------------------------------------------------------------------
    // Private: send unlock command to the ESP32 back-channel HTTP server
    // -------------------------------------------------------------------------------------

    /**
     * POST http://<esp32_ip>:8080/unlock
     *
     * There's no unlock duration: the ESP32 relocks off its door limit switch once the
     * door has been opened and closed again (or after a fallback timeout if unopened).
     *
     * Returns ['success' => bool, 'error' => string|null]
     */
    private function signalEsp32Unlock(?Device $device): array
    {
        if (!$device) {
            return ['success' => true, 'error' => null, 'queued' => false];
        }

        // 1. Queue command, keyed to this specific device, for cloud / NAT-isolated ESP32
        //    polling via /api/heartbeat (works over the Internet, no inbound port needed).
        \Illuminate\Support\Facades\Cache::put("trilock_pending_command_{$device->id}", [
            'command'   => 'unlock',
            'queued_at' => now()->toDateTimeString(),
        ], 60);

        // 2. Also attempt direct LAN push if this device's IP is reachable
        if (!$device->ip_address) {
            return ['success' => true, 'error' => null, 'queued' => true];
        }

        $url = "http://{$device->ip_address}:8080/unlock";

        try {
            // The ESP32 rejects this without the device token (see checkRemoteUnlockServer()).
            $response = Http::timeout(2)
                ->withHeaders(['X-Device-Token' => config('trilock.device_api_key')])
                ->post($url);

            if ($response->successful()) {
                // Delivered directly - drop the queued copy so the next heartbeat doesn't
                // execute the same command a second time.
                \Illuminate\Support\Facades\Cache::forget("trilock_pending_command_{$device->id}");
                DoorState::markUnlocked($device->id); // the lock confirmed it
                Log::info("ESP32 direct unlock signal sent successfully to {$url}", [
                    'device_id' => $device->id,
                ]);
                return ['success' => true, 'error' => null];
            }
        } catch (\Exception $e) {
            // In cloud deployments, direct LAN push will fail safely while cloud queue succeeds
            Log::info("Cloud remote unlock queued for ESP32: {$e->getMessage()}", ['device_id' => $device->id]);
        }

        return ['success' => true, 'error' => null, 'queued' => true];
    }

    /**
     * POST http://<esp32_ip>:8080/lock
     *
     * Returns ['success' => bool, 'error' => string|null]
     */
    private function signalEsp32Lock(?Device $device): array
    {
        if (!$device) {
            return ['success' => true, 'error' => null, 'queued' => false];
        }

        // 1. Queue command, keyed to this specific device, for cloud / NAT-isolated ESP32
        //    polling via /api/heartbeat.
        \Illuminate\Support\Facades\Cache::put("trilock_pending_command_{$device->id}", [
            'command'   => 'lock',
            'queued_at' => now()->toDateTimeString(),
        ], 60);

        // 2. Also attempt direct LAN push if this device's IP is reachable
        if (!$device->ip_address) {
            return ['success' => true, 'error' => null, 'queued' => true];
        }

        $url = "http://{$device->ip_address}:8080/lock";

        try {
            // The ESP32 rejects this without the device token (see checkRemoteUnlockServer()).
            $response = Http::timeout(2)
                ->withHeaders(['X-Device-Token' => config('trilock.device_api_key')])
                ->post($url);

            if ($response->successful()) {
                // Delivered directly - drop the queued copy so the next heartbeat doesn't
                // execute the same command a second time.
                \Illuminate\Support\Facades\Cache::forget("trilock_pending_command_{$device->id}");
                DoorState::markLocked($device->id); // the lock confirmed it
                Log::info("ESP32 direct lock signal sent successfully to {$url}", [
                    'device_id' => $device->id,
                ]);
                return ['success' => true, 'error' => null];
            }
        } catch (\Exception $e) {
            Log::info("Cloud remote lock queued for ESP32: {$e->getMessage()}", ['device_id' => $device->id]);
        }

        return ['success' => true, 'error' => null, 'queued' => true];
    }
}
