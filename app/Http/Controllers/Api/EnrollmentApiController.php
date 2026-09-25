<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\EnrollmentService;
use App\Services\Esp32EnrollmentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * EnrollmentApiController
 *
 * Handles communication initiated by the ESP32:
 *   - GET  /api/esp32/enroll/poll        (ESP32 checks for queued commands)
 *   - POST /api/esp32/enroll/pin/result   (ESP32 sends PIN typed on 4x3 keypad)
 *   - POST /api/esp32/enroll/fingerprint/result (ESP32 sends enrolled Fingerprint ID)
 *   - POST /api/esp32/enroll/rfid/result  (ESP32 sends scanned RFID UID)
 */
class EnrollmentApiController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $service,
    ) {}

    /**
     * GET /api/esp32/enroll/poll
     * ESP32 periodically polls this endpoint to check if an enrollment command is waiting.
     *
     * Requires device_id (the polling device's own ID) so that with more than one ESP32
     * main controller registered, each only ever sees commands queued for an enrollment
     * session that was started against it specifically — never one meant for a different
     * physical device.
     */
    public function poll(Request $request)
    {
        $token = $request->query('session_token');

        // This 3s poll is the main controller's most frequent check-in - count it so
        // last_seen stays current between its 30s heartbeats.
        if ($pollingDevice = Device::find((int) $request->query('device_id'))) {
            if ($pollingDevice->markSeen()) {
                Cache::forget('trilock_dashboard_status');
            }
        }

        if (!$token) {
            $deviceId = $request->query('device_id');
            if ($deviceId) {
                $activeToken = Cache::get("active_enrollment_token_{$deviceId}");
                if ($activeToken) {
                    $token = $activeToken;
                }
            }
        }

        if ($token) {
            $cmd = Esp32EnrollmentClient::dequeueCommand($token);
            if ($cmd) {
                return response()->json([
                    'status'        => 'command_pending',
                    'session_token' => $token,
                    'command'       => $cmd['command'],
                    'queued_at'     => $cmd['queued_at'],
                ]);
            }
        }

        return response()->json([
            'status'  => 'idle',
            'message' => 'No active enrollment command',
        ]);
    }

    /**
     * ESP32 → Laravel: Keypad PIN entry completed.
     * Expected body: { "session_token": "...", "pin": "1234", "status": "success" }
     */
    public function pinResult(Request $request)
    {
        // Failure reports from the firmware carry an empty value ("pin": "", "fingerprint_id": 0),
        // so the value is only validated on success - otherwise every failure 422'd and the
        // wizard never saw the device's error message.
        $validated = $request->validate([
            'session_token' => 'required|string|max:64',
            'pin'           => 'exclude_unless:status,success|required|string|max:8',
            'status'        => 'required|string|in:success,failed',
            'message'       => 'nullable|string',
        ]);

        if ($validated['status'] === 'success') {
            try {
                $this->service->receivePinResult(
                    $validated['session_token'],
                    $validated['pin']
                );

                return response()->json([
                    'status'  => 'ok',
                    'message' => 'PIN credential received and hashed.',
                ]);
            } catch (\Throwable $e) {
                Log::error("PIN result processing failed: {$e->getMessage()}");
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }
        }

        Cache::put(
            Esp32EnrollmentClient::pinCacheKey($validated['session_token']),
            [
                'status'  => 'error',
                'message' => $validated['message'] ?? 'PIN entry failed or timed out on keypad.',
            ],
            300
        );

        return response()->json(['status' => 'ok', 'message' => 'Failure recorded.']);
    }

    /**
     * ESP32 → Laravel: Fingerprint enrollment completed.
     * Expected body: { "session_token": "...", "fingerprint_id": 12, "status": "success" }
     */
    public function fingerprintResult(Request $request)
    {
        $validated = $request->validate([
            'session_token'  => 'required|string|max:64',
            'fingerprint_id' => 'exclude_unless:status,success|required|integer|min:1|max:127',
            'status'         => 'required|string|in:success,failed',
            'message'        => 'nullable|string',
        ]);

        if ($validated['status'] === 'success') {
            try {
                $this->service->receiveFingerprintResult(
                    $validated['session_token'],
                    $validated['fingerprint_id']
                );

                return response()->json([
                    'status'  => 'ok',
                    'message' => "Fingerprint ID {$validated['fingerprint_id']} received.",
                ]);
            } catch (\Throwable $e) {
                Log::error("Fingerprint result processing failed: {$e->getMessage()}");
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }

        Cache::put(
            Esp32EnrollmentClient::fingerprintCacheKey($validated['session_token']),
            [
                'status'  => 'error',
                'message' => $validated['message'] ?? 'Fingerprint enrollment failed on sensor.',
            ],
            300
        );

        return response()->json(['status' => 'ok', 'message' => 'Failure recorded.']);
    }

    /**
     * ESP32 → Laravel: RFID enrollment completed.
     * Expected body: { "session_token": "...", "rfid_uid": "A4:8B:2F:9C", "status": "success" }
     */
    public function rfidResult(Request $request)
    {
        $validated = $request->validate([
            'session_token' => 'required|string|max:64',
            'rfid_uid'      => 'exclude_unless:status,success|required|string|max:50',
            'status'        => 'required|string|in:success,failed',
            'message'       => 'nullable|string',
        ]);

        if ($validated['status'] === 'success') {
            try {
                $this->service->receiveRfidResult(
                    $validated['session_token'],
                    $validated['rfid_uid']
                );

                return response()->json([
                    'status'  => 'ok',
                    'message' => "RFID UID {$validated['rfid_uid']} received.",
                ]);
            } catch (\Throwable $e) {
                Log::error("RFID result processing failed: {$e->getMessage()}");
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }

        Cache::put(
            Esp32EnrollmentClient::rfidCacheKey($validated['session_token']),
            [
                'status'  => 'error',
                'message' => $validated['message'] ?? 'RFID scan failed on device.',
            ],
            300
        );

        return response()->json(['status' => 'ok', 'message' => 'Failure recorded.']);
    }
}
