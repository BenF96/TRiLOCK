<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Esp32EnrollmentClient
 *
 * Hands enrollment commands to the ESP32 main controller and keys the results it sends
 * back. Delivery is pull-only: the ESP32 polls GET /api/esp32/enroll/poll every ~3s and
 * picks up the command queued here, which works whether the server is on the same LAN
 * or in the cloud behind NAT.
 *
 * (This used to also POST each command straight to http://<esp32>:8080/api/esp32/enroll/*,
 * but the firmware never implemented those routes - every call just burned a 2-5s
 * timeout in the admin's request and stalled the lock's single-threaded loop while it
 * answered.)
 *
 * The device posts results to /api/esp32/enroll/{pin,fingerprint,rfid}/result, which
 * store them under the *CacheKey()s below for the wizard's SSE streams to pick up.
 */
class Esp32EnrollmentClient
{
    /** How long a queued command waits for the ESP32 to poll before it expires. */
    private const COMMAND_TTL = 120;

    public function requestPinEnrollment(string $sessionToken): void
    {
        self::queueCommand($sessionToken, 'pin');
    }

    public function requestFingerprintEnrollment(string $sessionToken): void
    {
        self::queueCommand($sessionToken, 'fingerprint');
    }

    public function requestRfidEnrollment(string $sessionToken): void
    {
        self::queueCommand($sessionToken, 'rfid');
    }

    /**
     * Drop any command the ESP32 hasn't picked up yet, so a step the admin already
     * finished/cancelled can't still start on the device afterwards.
     */
    public function finishEnrollment(string $sessionToken): void
    {
        Cache::forget(self::commandCacheKey($sessionToken));
    }

    public function cancelEnrollment(string $sessionToken): void
    {
        Cache::forget(self::commandCacheKey($sessionToken));
    }

    // -------------------------------------------------------------------------
    // Cache keys - results written by EnrollmentApiController, read by the SSE streams
    // -------------------------------------------------------------------------

    public static function fingerprintCacheKey(string $token): string
    {
        return "enroll_fp_{$token}";
    }

    public static function rfidCacheKey(string $token): string
    {
        return "enroll_rfid_{$token}";
    }

    public static function pinCacheKey(string $token): string
    {
        return "enroll_pin_{$token}";
    }

    // -------------------------------------------------------------------------
    // Command queue - ESP32 polls this via GET /api/esp32/enroll/poll
    // -------------------------------------------------------------------------

    public static function commandCacheKey(string $token): string
    {
        return "enroll_cmd_{$token}";
    }

    /**
     * Store a command that the ESP32 will pick up on its next poll.
     * command: 'pin' | 'fingerprint' | 'rfid'
     */
    public static function queueCommand(string $token, string $command): void
    {
        Cache::forget(self::pickupCacheKey($token)); // a new step starts un-acknowledged
        Cache::put(
            self::commandCacheKey($token),
            ['command' => $command, 'queued_at' => now()->toIso8601String()],
            self::COMMAND_TTL
        );
    }

    /**
     * Read and clear the pending command (ESP32 calls this on poll).
     * Returns null when there is no pending command.
     */
    public static function dequeueCommand(string $token): ?array
    {
        $cmd = Cache::pull(self::commandCacheKey($token));
        if ($cmd) {
            // The device has it and is entering enrollment mode now - the wizard waits for
            // this before telling the person at the door to start (see
            // EnrollmentController::waitForDevicePickup()).
            Cache::put(self::pickupCacheKey($token), $cmd['command'], 600);
        }
        return $cmd;
    }

    /** Which command the ESP32 has picked up for this session (null = not yet). */
    public static function pickupCacheKey(string $token): string
    {
        return "enroll_pickup_{$token}";
    }
}
