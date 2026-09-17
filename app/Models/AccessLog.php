<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'authentication_method',
        'access_type',
        'captured_image',
        'door_status',
        'date',
        'time',
        'device_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Public URL for the ESP32-CAM capture, resolved against whichever disk
     * FILESYSTEM_DISK points to (local for a single instance, S3 when
     * running multiple app instances behind a load balancer).
     */
    public function getCapturedImageUrlAttribute(): ?string
    {
        if (!$this->captured_image) {
            return null;
        }

        if (str_starts_with($this->captured_image, 'http://') || str_starts_with($this->captured_image, 'https://')) {
            return $this->captured_image;
        }

        return \Illuminate\Support\Facades\Storage::url($this->captured_image);
    }
}
