<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Esp32EnrollmentClient
 *
 * Handles all HTTP communication with the ESP32 during the enrollment workflow.
 * Each method communicates with a specific ESP32 enrollment endpoint and stores
 * intermediate results in the Laravel cache so the SSE stream can pick them up.
 */
class Esp32EnrollmentClient
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = $this->getBaseUrl();
        $this->timeout = (int) config('trilock.enrollment_timeout', 60);
    }

    public function getBaseUrl(?int $deviceId = null): string
    {
        if ($deviceId) {
            $device = \App\Models\Device::find($deviceId);
            if ($device && !empty($device->ip_address)) {
                return "http://{$device->ip_address}:8080";
            }
        }

        $mainDevice = \App\Models\Device::where('device_type', 'esp32_main')
            ->whereNotNull('ip_address')
            ->orderByDesc('last_seen')
            ->first();

        if ($mainDevice && !empty($mainDevice->ip_address)) {
            return "http://{$mainDevice->ip_address}:8080";
        }

        return rtrim(config('trilock.esp32_url', 'http://192.168.1.106'), '/');
    }

    // -------------------------------------------------------------------------
    // Connectivity
    // -------------------------------------------------------------------------

    /**
     * Returns true when the ESP32 responds to a lightweight ping.
     */
    public function isOnline(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->getBaseUrl()}/api/status");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Enrollment Lifecycle
    // -------------------------------------------------------------------------

    /**
     * PUT ESP32 into enrollment mode and return the session acknowledgement.
     */
    public function startEnrollment(string $sessionToken): array
    {
        $response = Http::timeout(10)->post("{$this->baseUrl}/api/esp32/enroll/start", [
            'session_token' => $sessionToken,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('ESP32 failed to start enrollment session: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Command the ESP32 to begin fingerprint capture.
     * Always queues command for ESP32 polling (/api/esp32/enroll/poll), and optionally attempts direct HTTP push.
     */
    public function requestFingerprintEnrollment(string $sessionToken): array
    {
        self::queueCommand($sessionToken, 'fingerprint');

        try {
            $response = Http::timeout(2)->post("{$this->baseUrl}/api/esp32/enroll/fingerprint", [
                'session_token' => $sessionToken,
                'callback_url'  => route('api.enroll.fingerprint.result'),
            ]);
            return $response->successful() ? $response->json() : ['status' => 'queued'];
        } catch (\Throwable $e) {
            // Non-fatal — command is queued for ESP32 polling
            Log::info("ESP32 direct HTTP push skipped, queued for polling: {$e->getMessage()}");
            return ['status' => 'queued', 'message' => 'Command queued for ESP32 polling.'];
        }
    }

    /**
     * Command the ESP32 to enter RFID scan mode.
     * Always queues command for ESP32 polling (/api/esp32/enroll/poll), and optionally attempts direct HTTP push.
     */
    public function requestRfidEnrollment(string $sessionToken): array
    {
        self::queueCommand($sessionToken, 'rfid');

        try {
            $response = Http::timeout(2)->post("{$this->baseUrl}/api/esp32/enroll/rfid", [
                'session_token' => $sessionToken,
                'callback_url'  => route('api.enroll.rfid.result'),
            ]);
            return $response->successful() ? $response->json() : ['status' => 'queued'];
        } catch (\Throwable $e) {
            Log::info("ESP32 direct HTTP push skipped, queued for polling: {$e->getMessage()}");
            return ['status' => 'queued', 'message' => 'Command queued for ESP32 polling.'];
        }
    }

    /**
     * Tell the ESP32 enrollment is complete and return to normal operation.
     */
    public function finishEnrollment(string $sessionToken): void
    {
        try {
            Http::timeout(5)->post("{$this->baseUrl}/api/esp32/enroll/finish", [
                'session_token' => $sessionToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ESP32 finish enrollment signal failed: {$e->getMessage()}");
        }
    }

    /**
     * Tell the ESP32 to cancel enrollment and revert to normal mode.
     */
    public function cancelEnrollment(string $sessionToken): void
    {
        try {
            Http::timeout(5)->post("{$this->baseUrl}/api/esp32/enroll/cancel", [
                'session_token' => $sessionToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ESP32 cancel enrollment signal failed: {$e->getMessage()}");
        }
    }

    // -------------------------------------------------------------------------
    // Polling helpers (used by SSE stream to poll cache for ESP32 result)
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
    // Command queue key — ESP32 polls this via GET /api/esp32/enroll/poll
    // -------------------------------------------------------------------------

    /**
     * Cache key for the pending enrollment command.
     * Written by Laravel when a step starts; read and cleared by the ESP32 via poll.
     */
    public static function commandCacheKey(string $token): string
    {
        return "enroll_cmd_{$token}";
    }

    /**
     * Store a command that the ESP32 will pick up on its next poll.
     * command: 'pin' | 'fingerprint' | 'rfid' | 'finish' | 'cancel'
     */
    public static function queueCommand(string $token, string $command): void
    {
        \Illuminate\Support\Facades\Cache::put(
            self::commandCacheKey($token),
            ['command' => $command, 'queued_at' => now()->toIso8601String()],
            120 // 2-minute TTL — if ESP32 doesn't poll within 2 min, command expires
        );
    }

    /**
     * Read and clear the pending command (ESP32 calls this on poll).
     * Returns null when there is no pending command.
     */
    public static function dequeueCommand(string $token): ?array
    {
        $key = self::commandCacheKey($token);
        $cmd = \Illuminate\Support\Facades\Cache::get($key);
        if ($cmd) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        return $cmd;
    }

    /**
     * Command the ESP32 to enter PIN-input mode via the 4×3 keypad.
     * We queue the command; the ESP32 picks it up on its next poll.
     */
    public function requestPinEnrollment(string $sessionToken): void
    {
        self::queueCommand($sessionToken, 'pin');
    }
}
