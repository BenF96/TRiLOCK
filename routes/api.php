<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HardwareApiController;
use App\Http\Controllers\Api\EnrollmentApiController;

/*
|--------------------------------------------------------------------------
| ESP32 & Hardware REST API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['throttle:hardware-api', 'device.auth'])->group(function () {
    // Settings polled by ESP32 (device-authenticated)
    Route::get('/settings',    [HardwareApiController::class, 'getSettings']);

    // Offline credential cache — periodically synced to device flash for
    // fully-offline RFID+fingerprint+PIN verification.
    Route::get('/offline-sync', [HardwareApiController::class, 'offlineSync']);

    // Core hardware operations
    Route::post('/access',     [HardwareApiController::class, 'processAccess']);
    Route::post('/door-status',[HardwareApiController::class, 'updateDoorStatus']);
    Route::post('/heartbeat',  [HardwareApiController::class, 'heartbeat']);

    // Backfills a door unlock that was granted from the device's local offline cache
    // (server was unreachable at the time) once connectivity returns.
    Route::post('/access/offline-log', [HardwareApiController::class, 'logOfflineAccess']);

    // Media upload
    Route::post('/upload-image',[HardwareApiController::class, 'uploadImage']);

    // ESP32 Enrollment Callbacks (ESP32 → Laravel)
    Route::get('/esp32/enroll/poll',                [EnrollmentApiController::class, 'poll'])->name('api.enroll.poll');
    Route::post('/esp32/enroll/fingerprint/result', [EnrollmentApiController::class, 'fingerprintResult'])->name('api.enroll.fingerprint.result');
    Route::post('/esp32/enroll/rfid/result',        [EnrollmentApiController::class, 'rfidResult'])->name('api.enroll.rfid.result');
    Route::post('/esp32/enroll/pin/result',         [EnrollmentApiController::class, 'pinResult'])->name('api.enroll.pin.result');
});
