<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    use HasFactory;

    /**
     * Why a denied attempt was rejected -> human-readable label for the dashboard.
     * Stored in access_logs.denial_reason (null for granted entries).
     */
    public const DENIAL_REASONS = [
        'missing_factors'            => 'Incomplete credentials',
        'rfid_not_recognized'        => 'RFID card not recognized',
        'fingerprint_not_recognized' => 'Fingerprint not recognized',
        'pin_incorrect'              => 'Incorrect PIN',
        'credential_mismatch'        => 'Credentials belong to different users',
        'user_inactive'              => 'User account inactive',
        'lockout_active'             => 'Attempt during security lockout',
        'unidentified_capture'       => 'Unlinked camera capture',
    ];

    /**
     * Reasons the ESP32 is allowed to report itself for rejections it decides locally
     * (e.g. the fingerprint sensor finds no matching template, so there's no ID to send).
     */
    public const DEVICE_DENIAL_REASONS = [
        'fingerprint_not_recognized',
    ];

    protected $fillable = [
        'user_id',
        'authentication_method',
        'access_type',
        'denial_reason',
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

    public function getDenialReasonLabelAttribute(): ?string
    {
        if (!$this->denial_reason) {
            return null;
        }

        return self::DENIAL_REASONS[$this->denial_reason] ?? str_replace('_', ' ', ucfirst($this->denial_reason));
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
