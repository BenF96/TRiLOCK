<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RfidController;
use App\Http\Controllers\FingerprintController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\CameraMonitoringController;
use App\Http\Controllers\DoorLockController;
use App\Http\Controllers\AccessLogController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\DeviceController;

// Auth Routes
Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Local Quick Auth for Automated Documentation
if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        $user = \App\Models\User::where('role', 'admin')->first() ?? \App\Models\User::first();
        if ($user) {
            auth()->login($user);
        }
        return redirect()->route('dashboard');
    });

    \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, function ($event) {
        if (request()->query('doc_auth') === '1' && !auth()->check()) {
            $user = \App\Models\User::where('role', 'admin')->first() ?? \App\Models\User::first();
            if ($user) {
                auth()->login($user);
            }
        }
    });
}

// Authenticated Routes — Restricted to Admin Role Only
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/status', [\App\Http\Controllers\Api\HardwareApiController::class, 'getStatus'])->name('dashboard.status');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');

    // User Management
    Route::resource('users', UserController::class)->except('show');
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

    // ESP32 Device Registry
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('/devices/create', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('/devices/{device}/edit', [DeviceController::class, 'edit'])->name('devices.edit');
    Route::put('/devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('/devices/{device}/regenerate-token', [DeviceController::class, 'regenerateToken'])->name('devices.regenerate-token');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    // Hardware Enrollment Wizard
    Route::get('/enroll', [EnrollmentController::class, 'wizard'])->name('enroll.wizard');
    Route::post('/enroll/session/start', [EnrollmentController::class, 'startSession'])->name('enroll.session.start');
    Route::post('/enroll/user', [EnrollmentController::class, 'saveUser'])->name('enroll.user');
    Route::post('/enroll/pin', [EnrollmentController::class, 'savePin'])->name('enroll.pin');
    Route::post('/enroll/fingerprint/start', [EnrollmentController::class, 'startFingerprint'])->name('enroll.fingerprint.start');
    Route::get('/enroll/fingerprint/status', [EnrollmentController::class, 'fingerprintStatus'])->name('enroll.fingerprint.status');
    Route::post('/enroll/rfid/start', [EnrollmentController::class, 'startRfid'])->name('enroll.rfid.start');
    Route::get('/enroll/rfid/status', [EnrollmentController::class, 'rfidStatus'])->name('enroll.rfid.status');
    Route::post('/enroll/commit', [EnrollmentController::class, 'commit'])->name('enroll.commit');
    Route::post('/enroll/cancel', [EnrollmentController::class, 'cancel'])->name('enroll.cancel');
    Route::post('/enroll/pin/start', [EnrollmentController::class, 'startPin'])->name('enroll.pin.start');
    Route::get('/enroll/pin/status', [EnrollmentController::class, 'pinStatus'])->name('enroll.pin.status');

    // RFID Cards Management
    Route::get('/rfid', [RfidController::class, 'index'])->name('rfid.index');
    Route::post('/rfid', [RfidController::class, 'store'])->name('rfid.store');
    Route::post('/rfid/{card}/assign', [RfidController::class, 'assign'])->name('rfid.assign');
    Route::patch('/rfid/{card}/toggle-status', [RfidController::class, 'toggleStatus'])->name('rfid.toggle-status');
    Route::patch('/rfid/{card}/mark-lost', [RfidController::class, 'markLost'])->name('rfid.mark-lost');
    Route::delete('/rfid/{card}', [RfidController::class, 'destroy'])->name('rfid.destroy');

    // Fingerprint Management
    Route::get('/fingerprints', [FingerprintController::class, 'index'])->name('fingerprints.index');
    Route::post('/fingerprints', [FingerprintController::class, 'store'])->name('fingerprints.store');
    Route::patch('/fingerprints/{fingerprint}/toggle-status', [FingerprintController::class, 'toggleStatus'])->name('fingerprints.toggle-status');
    Route::delete('/fingerprints/{fingerprint}', [FingerprintController::class, 'destroy'])->name('fingerprints.destroy');

    // Keypad PIN Management
    Route::get('/pins', [PinController::class, 'index'])->name('pins.index');
    Route::post('/pins', [PinController::class, 'store'])->name('pins.store');
    Route::patch('/pins/{pin}/toggle-status', [PinController::class, 'toggleStatus'])->name('pins.toggle-status');
    Route::delete('/pins/{pin}', [PinController::class, 'destroy'])->name('pins.destroy');

    // Camera Monitoring & Gallery
    Route::get('/camera', [CameraMonitoringController::class, 'index'])->name('camera.index');
    Route::delete('/camera/{log}', [CameraMonitoringController::class, 'destroy'])->name('camera.destroy');

    // Door Lock Management
    Route::get('/door', [DoorLockController::class, 'index'])->name('door.index');
    Route::post('/door/unlock', [DoorLockController::class, 'unlock'])->name('door.unlock');
    Route::post('/door/lock',   [DoorLockController::class, 'lock'])->name('door.lock');

    // Access Logs
    Route::get('/access-logs', [AccessLogController::class, 'index'])->name('logs.index');
    Route::delete('/access-logs/{log}', [AccessLogController::class, 'destroy'])->name('logs.destroy');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export-csv', [ReportController::class, 'exportCsv'])->name('reports.export-csv');

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/backup', [SettingController::class, 'backupDatabase'])->name('settings.backup');
    Route::get('/settings/backups/{filename}', [SettingController::class, 'downloadBackup'])->name('settings.backup.download');
    Route::post('/settings/restore', [SettingController::class, 'restoreDatabase'])->name('settings.restore');

});
