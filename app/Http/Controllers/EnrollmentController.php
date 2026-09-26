<?php

namespace App\Http\Controllers;

use App\Services\EnrollmentService;
use App\Services\Esp32EnrollmentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EnrollmentController
 *
 * Handles the web-facing enrollment wizard.
 * All steps communicate with the physical ESP32 hardware:
 *   - PIN: Hardware 4x3 matrix keypad input
 *   - Fingerprint: AS608 / R503 sensor
 *   - RFID: MFRC522 card reader
 */
class EnrollmentController extends Controller
{
    /**
     * Worst-case seconds from "step started" until the ESP32's result can arrive: up to
     * 3s for its next poll, its own on-device wait, and the 8s result POST. The SSE stream
     * must outlast this - otherwise a slow-but-successful scan showed "Timeout" in the
     * wizard while the device was still working, and the result landed unseen.
     */
    private const PIN_DEVICE_WINDOW         = 60; // 3 + 45s keypad wait + 8
    private const RFID_DEVICE_WINDOW        = 60; // 3 + 45s card wait + 8
    private const FINGERPRINT_DEVICE_WINDOW = 90; // 3 + 30s scan + 2s + 10s lift + 30s scan + 8

    /**
     * How long a step waits for the ESP32 to pick up its command. The lock only starts
     * polling for commands once its heartbeat (every 30s) says an enrollment is open for
     * it, then polls every ~3s - and it defers that while a door attempt is in progress.
     * The session opens when the wizard loads, so by the first step the lock is usually
     * already polling; this covers the worst case.
     */
    private const PICKUP_TIMEOUT = 45;

    public function __construct(
        private readonly EnrollmentService     $service,
        private readonly Esp32EnrollmentClient $esp32,
    ) {}

    // -------------------------------------------------------------------------
    // Wizard View
    // -------------------------------------------------------------------------

    public function wizard()
    {
        $mainControllers = \App\Models\Device::where('device_type', 'esp32_main')
            ->orderBy('device_name')->get();

        return view('enrollment.wizard', compact('mainControllers'));
    }

    // -------------------------------------------------------------------------
    // Step 0: Start Session
    // -------------------------------------------------------------------------

    public function startSession(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'nullable|exists:devices,id',
        ]);

        try {
            $token = $this->service->startSession($request->user()->id, $validated['device_id'] ?? null);
            return response()->json(['status' => 'ok', 'session_token' => $token]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Step 1: User Info
    // -------------------------------------------------------------------------

    public function saveUser(Request $request)
    {
        $validated = $request->validate([
            'session_token'       => 'required|string|max:64',
            'employee_student_id' => 'required|string|max:100|unique:users,employee_student_id',
            'first_name'          => 'required|string|max:255',
            'middle_name'         => 'nullable|string|max:255',
            'last_name'           => 'required|string|max:255',
            'email'               => 'required|email|max:255|unique:users,email',
            'role'                => 'required|in:admin,student,faculty,lab_tech',
            'status'              => 'required|in:active,inactive',
            'photo'               => 'nullable|image|max:2048',
        ]);

        try {
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('photos');
            }

            $user = $this->service->saveUserInfo(
                $validated['session_token'],
                $validated,
                $photoPath
            );

            return response()->json([
                'status'  => 'ok',
                'user_id' => $user->id,
                'name'    => $user->full_name,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Step 2: PIN via Hardware Keypad — Start Command
    // -------------------------------------------------------------------------

    public function startPin(Request $request)
    {
        $request->validate(['session_token' => 'required|string|max:64']);

        try {
            $this->service->startPinEnrollment($request->session_token);
            return response()->json(['status' => 'ok', 'message' => 'Keypad PIN enrollment mode activated.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Step 2: PIN via Hardware Keypad — SSE Status Stream
    // -------------------------------------------------------------------------

    public function pinStatus(Request $request): StreamedResponse
    {
        $token   = $request->query('session_token', '');
        $timeout = $this->streamTimeout(self::PIN_DEVICE_WINDOW);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);

            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $this->sseSend('status', 'Waiting for the lock to enter PIN enrollment mode...');
            if (!$this->waitForDevicePickup($token, 'pin')) {
                return;
            }

            $start = time();
            $this->sseSend('status', 'Lock ready — enter a 4–8 digit PIN on the keypad, then press #');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::pinCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message' => 'PIN Registered via Hardware Keypad!',
                        ]));
                    } else {
                        $this->sseSend('step_error', $result['message'] ?? 'PIN entry failed.');
                    }
                    return;
                }

                // Periodic comment heartbeat to keep connection alive
                echo ": ping\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                sleep(1);
            }

            $this->sseSend('step_error', 'Timeout: No PIN entered on keypad. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // Legacy web form savePin fallback
    public function savePin(Request $request)
    {
        $request->validate([
            'session_token' => 'required|string|max:64',
            'pin'           => 'required|string|digits_between:4,8',
        ]);

        try {
            $this->service->savePin($request->session_token, $request->pin);
            return response()->json(['status' => 'ok', 'message' => 'PIN stored.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: Fingerprint — Start Command
    // -------------------------------------------------------------------------

    public function startFingerprint(Request $request)
    {
        $request->validate(['session_token' => 'required|string|max:64']);

        try {
            $this->service->startFingerprintEnrollment($request->session_token);
            return response()->json(['status' => 'ok', 'message' => 'Fingerprint enrollment mode activated.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: Fingerprint — SSE Status Stream
    // -------------------------------------------------------------------------

    public function fingerprintStatus(Request $request): StreamedResponse
    {
        $token   = $request->query('session_token', '');
        $timeout = $this->streamTimeout(self::FINGERPRINT_DEVICE_WINDOW);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);

            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $this->sseSend('status', 'Waiting for the lock to enter fingerprint enrollment mode...');
            if (!$this->waitForDevicePickup($token, 'fingerprint')) {
                return;
            }

            $start = time();
            $this->sseSend('status', 'Lock ready — place finger on the sensor');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::fingerprintCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message'        => 'Fingerprint Enrolled Successfully!',
                            'fingerprint_id' => $result['fingerprint_id'],
                        ]));
                    } else {
                        $this->sseSend('step_error', $result['message'] ?? 'Fingerprint enrollment failed.');
                    }
                    return;
                }

                // Periodic comment heartbeat to keep connection alive
                echo ": ping\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                sleep(1);
            }

            $this->sseSend('step_error', 'Timeout: No fingerprint detected. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 4: RFID — Start Command
    // -------------------------------------------------------------------------

    public function startRfid(Request $request)
    {
        $request->validate(['session_token' => 'required|string|max:64']);

        try {
            $this->service->startRfidEnrollment($request->session_token);
            return response()->json(['status' => 'ok', 'message' => 'RFID enrollment mode activated.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Step 4: RFID — SSE Status Stream
    // -------------------------------------------------------------------------

    public function rfidStatus(Request $request): StreamedResponse
    {
        $token   = $request->query('session_token', '');
        $timeout = $this->streamTimeout(self::RFID_DEVICE_WINDOW);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);

            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $this->sseSend('status', 'Waiting for the lock to enter RFID enrollment mode...');
            if (!$this->waitForDevicePickup($token, 'rfid')) {
                return;
            }

            $start = time();
            $this->sseSend('status', 'Lock ready — tap the RFID card on the reader');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::rfidCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message'  => 'RFID Card Registered Successfully!',
                            'rfid_uid' => $result['rfid_uid'],
                        ]));
                    } else {
                        $this->sseSend('step_error', $result['message'] ?? 'RFID registration failed.');
                    }
                    return;
                }

                // Periodic comment heartbeat to keep connection alive
                echo ": ping\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                sleep(1);
            }

            $this->sseSend('step_error', 'Timeout: No RFID card detected. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 5: Commit
    // -------------------------------------------------------------------------

    public function commit(Request $request)
    {
        $request->validate(['session_token' => 'required|string|max:64']);

        try {
            $user = $this->service->commit($request->session_token);

            return response()->json([
                'status'  => 'ok',
                'message' => "User {$user->full_name} enrolled successfully.",
                'user'    => [
                    'id'                  => $user->id,
                    'name'                => $user->full_name,
                    'employee_student_id' => $user->employee_student_id,
                    'role'                => $user->role,
                    'email'               => $user->email,
                    'rfid_uid'            => $user->activeRfidCard?->card_uid,
                    'fingerprint_id'      => $user->activeFingerprint?->fingerprint_id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("Enrollment commit failed: {$e->getMessage()}");
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Cancel / Rollback
    // -------------------------------------------------------------------------

    public function cancel(Request $request)
    {
        $request->validate(['session_token' => 'required|string|max:64']);

        try {
            $this->service->cancel($request->session_token, 'Admin cancelled enrollment via UI');
            return response()->json(['status' => 'ok', 'message' => 'Enrollment cancelled and rolled back.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Holds the step's SSE stream until the ESP32 has actually picked the command up.
     * The prompt used to appear ~1s after the step started, but the lock only polls every
     * ~3s - and not at all while someone is mid-entry at its keypad. Anyone who typed
     * right away was typing into the normal door-unlock flow (which in turn held off the
     * poll), and the lock entered enrollment mode after they'd finished, then timed out.
     *
     * Returns false (having sent step_error) if the lock never picks it up.
     */
    private function waitForDevicePickup(string $token, string $command): bool
    {
        $start = time();
        $hinted = false;

        while ((time() - $start) < self::PICKUP_TIMEOUT) {
            if (Cache::get(Esp32EnrollmentClient::pickupCacheKey($token)) === $command) {
                return true;
            }

            if (!$hinted && (time() - $start) >= 8) {
                $hinted = true;
                $this->sseSend('status', 'Still waiting for the lock… it checks in every 30 seconds, and waits while someone is using its keypad or scanner — let any attempt at the door finish.');
            }

            echo ": ping\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();

            sleep(1);
        }

        // Withdraw it, so the lock can't drop into enrollment mode later with nobody there.
        $this->esp32->cancelEnrollment($token);
        $this->sseSend('step_error', 'The lock did not respond. Check it is online (Devices page) and that nobody is mid-entry at the keypad, then try again.');

        return false;
    }

    private function streamTimeout(int $deviceWindow): int
    {
        return max((int) config('trilock.enrollment_timeout', 60), $deviceWindow);
    }

    // -------------------------------------------------------------------------
    // Internal: SSE Helper
    // -------------------------------------------------------------------------

    private function sseSend(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
