<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'door_unlock_duration',
        'auto_lock',
        'camera_enabled',
        'capture_entry',
        'buzzer_enabled',
        'lab_name',
    ];

    protected $casts = [
        'auto_lock' => 'boolean',
        'camera_enabled' => 'boolean',
        'capture_entry' => 'boolean',
        'buzzer_enabled' => 'boolean',
    ];

    public static function getSettings(): self
    {
        $cached = \Illuminate\Support\Facades\Cache::get('trilock_system_settings');

        // A torn/corrupted cache write can come back as __PHP_Incomplete_Class
        // rather than a real Setting instance — never trust it blindly, since
        // the strict return type below would turn that into a hard TypeError
        // and take down every page that loads settings. Repair it instead.
        if ($cached instanceof self) {
            return $cached;
        }

        $settings = self::firstOrCreate([], [
            'door_unlock_duration' => 5,
            'auto_lock'            => true,
            'camera_enabled'       => true,
            'capture_entry'        => true,
            'buzzer_enabled'       => true,
            'lab_name'             => 'Access Control Zone',
        ]);

        \Illuminate\Support\Facades\Cache::put('trilock_system_settings', $settings, 300);

        return $settings;
    }

    protected static function booted()
    {
        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('trilock_system_settings');
        });
    }
}
