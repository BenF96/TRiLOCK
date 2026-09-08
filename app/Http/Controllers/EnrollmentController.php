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
    // Step 2: PIN via Hardware Keypad — SSE Status Stream
    // -------------------------------------------------------------------------

    public function pinStatus(Request $request): StreamedResponse
    {
        $token   = $request->query('session_token', '');
        $timeout = (int) config('trilock.enrollment_timeout', 60);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);
            $start = time();

            $this->sseSend('status', 'Connecting to ESP32 Keypad...');
            sleep(1);
            $this->sseSend('status', 'Enter 4–8 digit PIN on Keypad, then press #');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::pinCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message' => 'PIN Registered via Hardware Keypad!',
                        ]));
                    } else {
                        $this->sseSend('error', $result['message'] ?? 'PIN entry failed.');
                    }
                    return;
                }

                ob_flush();
                flush();
                sleep(1);
            }

            $this->sseSend('error', 'Timeout: No PIN entered on keypad. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
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
        $timeout = (int) config('trilock.enrollment_timeout', 60);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);
            $start = time();

            $this->sseSend('status', 'Connecting to ESP32 Fingerprint Sensor...');
            sleep(1);
            $this->sseSend('status', 'Place finger on AS608/R503 sensor');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::fingerprintCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message'        => 'Fingerprint Enrolled Successfully!',
                            'fingerprint_id' => $result['fingerprint_id'],
                        ]));
                    } else {
                        $this->sseSend('error', $result['message'] ?? 'Fingerprint enrollment failed.');
                    }
                    return;
                }

                ob_flush();
                flush();
                sleep(1);
            }

            $this->sseSend('error', 'Timeout: No fingerprint detected. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
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
        $timeout = (int) config('trilock.enrollment_timeout', 60);

        return response()->stream(function () use ($token, $timeout) {
            set_time_limit(0);
            ignore_user_abort(true);
            $start = time();

            $this->sseSend('status', 'Connecting to ESP32 RFID Reader...');
            sleep(1);
            $this->sseSend('status', 'Tap RFID Card on MFRC522 module');

            while ((time() - $start) < $timeout) {
                $result = Cache::get(Esp32EnrollmentClient::rfidCacheKey($token));

                if ($result) {
                    if (($result['status'] ?? '') === 'success') {
                        $this->sseSend('success', json_encode([
                            'message'  => 'RFID Card Registered Successfully!',
                            'rfid_uid' => $result['rfid_uid'],
                        ]));
                    } else {
                        $this->sseSend('error', $result['message'] ?? 'RFID registration failed.');
                    }
                    return;
                }

                ob_flush();
                flush();
                sleep(1);
            }

            $this->sseSend('error', 'Timeout: No RFID card detected. Please try again.');
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
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

    // -------------------------------------------------------------------------
    // Internal: SSE Helper
    // -------------------------------------------------------------------------

    private function sseSend(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
    }
}
