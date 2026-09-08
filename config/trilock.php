<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ESP32 Main Controller URL
    |--------------------------------------------------------------------------
    | The base URL of the ESP32 Main Controller on the local network.
    | Change this in .env via TRILOCK_ESP32_URL.
    */
    'esp32_url' => env('TRILOCK_ESP32_URL', 'http://192.168.1.106'),

    /*
    |--------------------------------------------------------------------------
    | Device Authentication Key (Default / Fallback)
    |--------------------------------------------------------------------------
    | Shared secret key for ESP32 devices when per-device tokens are not used.
    | Sent via X-Device-Token or Authorization: Bearer <token>.
    */
    'device_api_key' => env('TRILOCK_DEVICE_API_KEY', 'trilock_sec_dev_key_2026'),

    /*
    |--------------------------------------------------------------------------
    | Enrollment Timeout
    |--------------------------------------------------------------------------
    | Maximum seconds to wait for fingerprint/RFID response from the ESP32.
    */
    'enrollment_timeout' => (int) env('TRILOCK_ENROLLMENT_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Security & Anti-Brute Force Lockout
    |--------------------------------------------------------------------------
    | Maximum consecutive failed access attempts before temporary device lockout.
    */
    'max_failed_attempts' => (int) env('TRILOCK_MAX_FAILED_ATTEMPTS', 5),
    'lockout_duration'    => (int) env('TRILOCK_LOCKOUT_DURATION', 60), // seconds

    /*
    |--------------------------------------------------------------------------
    | Offline PIN Salt
    |--------------------------------------------------------------------------
    | Shared secret mixed into the fast (SHA-256) PIN hash that gets synced to
    | ESP32 devices for offline verification — must match the OFFLINE_PIN_SALT
    | constant in firmware/esp32_main_controller.ino exactly, or every offline
    | PIN check will fail. Not the same secret as TRILOCK_DEVICE_API_KEY.
    */
    'offline_pin_salt' => env('TRILOCK_OFFLINE_PIN_SALT', 'trilock_offline_salt_change_me'),
];
