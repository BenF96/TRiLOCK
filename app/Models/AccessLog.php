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
        return $this->captured_image ? \Illuminate\Support\Facades\Storage::url($this->captured_image) : null;
    }
}
