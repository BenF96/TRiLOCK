<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class TriLockSeeder extends Seeder
{
    public function run(): void
    {
        // Default System Hardware Configuration only
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
    }
}
