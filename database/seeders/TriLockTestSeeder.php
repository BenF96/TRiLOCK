<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Device;
use App\Models\RfidCard;
use App\Models\Fingerprint;
use App\Models\AccessPin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TriLockTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Settings
        Setting::updateOrCreate(
            ['id' => 1],
            [
                'door_unlock_duration' => 5,
                'auto_lock'            => true,
                'camera_enabled'       => true,
                'capture_entry'        => true,
                'buzzer_enabled'       => true,
                'lab_name'             => 'Access Control Zone',
            ]
        );

        // 2. Test Administrator
        $admin = User::firstOrCreate(
            ['email' => 'admin@trilock.lab'],
            [
                'employee_student_id' => 'EMP-TEST-001',
                'name'                => 'Test Administrator',
                'first_name'          => 'Test',
                'last_name'           => 'Admin',
                'password'            => Hash::make('admin1234'),
                'role'                => 'admin',
                'status'              => 'active',
            ]
        );

        // 3. Test Devices
        Device::firstOrCreate(
            ['device_name' => 'Main Door ESP32'],
            [
                'device_type' => 'esp32_main',
                'ip_address'  => '192.168.1.106',
                'mac_address' => '24:0A:C4:00:01:01',
                'api_token'   => 'trilock_dev_token_main_' . Str::random(16),
                'status'      => 'online',
                'last_seen'   => now(),
            ]
        );

        Device::firstOrCreate(
            ['device_name' => 'Security Camera ESP32-CAM'],
            [
                'device_type' => 'esp32_cam',
                'ip_address'  => '192.168.1.107',
                'mac_address' => '24:0A:C4:00:01:02',
                'api_token'   => 'trilock_dev_token_cam_' . Str::random(16),
                'status'      => 'online',
                'last_seen'   => now(),
            ]
        );

        // 4. Test Credentials (RFID, Fingerprint, PIN)
        RfidCard::firstOrCreate(
            ['card_uid' => 'A1B2C3D4'],
            [
                'user_id'     => $admin->id,
                'status'      => 'active',
                'assigned_at' => now(),
            ]
        );

        Fingerprint::firstOrCreate(
            ['fingerprint_id' => 1],
            [
                'user_id'       => $admin->id,
                'status'        => 'active',
                'registered_at' => now(),
            ]
        );

        $salt = config('trilock.offline_pin_salt', 'ab80053da114cdb5b40512dabcb63bbd');
        AccessPin::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'pin_code'     => Hash::make('1234'),
                'offline_hash' => hash('sha256', '1234' . $salt),
                'status'       => 'active',
                'assigned_at'  => now(),
            ]
        );
    }
}
