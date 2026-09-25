<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_name',
        'device_type',
        'ip_address',
        'mac_address',
        'api_token',
        'status',
        'last_seen',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
    ];

    /**
     * Seconds since the last heartbeat/request after which a device is considered offline.
     */
    public const ONLINE_THRESHOLD_SECONDS = 90;

    /**
     * Single source of truth for "is this device online right now" — computed live from
     * last_seen, never from the `status` column. That column is only ever write-through
     * (touchDevice() sets it on each request; getStatus() lazily corrects it when polled),
     * so a device that goes quiet between polls can sit showing stale "online" in any view
     * that reads `status` directly instead of calling this.
     *
     * Deliberately compares via greaterThan() rather than diffInSeconds() < threshold:
     * this Carbon version's diffInSeconds() returns a *signed* difference by default (a
     * Carbon 3.x change), and last_seen is always in the past — so diffInSeconds() is
     * always negative, and "always negative < 90" was always true. That inverted bug sat
     * in the original getStatus() method (and everywhere this was later centralized from)
     * and meant every device with any heartbeat history showed "online" forever.
     */
    public function isOnline(): bool
    {
        return $this->last_seen !== null
            && $this->last_seen->greaterThan(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Seconds within which a repeat check-in (e.g. the main controller's 3s enrollment
     * poll) skips the database write - far below ONLINE_THRESHOLD_SECONDS, so it can't
     * make a live device look offline.
     */
    public const SEEN_WRITE_INTERVAL_SECONDS = 15;

    /**
     * Record that this device just checked in. Returns true if the row was written.
     */
    public function markSeen(?string $ip = null): bool
    {
        $ipChanged = $ip !== null && $ip !== $this->ip_address;
        $fresh = $this->status === 'online' && $this->last_seen
            && $this->last_seen->greaterThan(now()->subSeconds(self::SEEN_WRITE_INTERVAL_SECONDS));

        if ($fresh && !$ipChanged) {
            return false;
        }

        $data = ['status' => 'online', 'last_seen' => now()];
        if ($ip !== null) {
            $data['ip_address'] = $ip;
        }

        return $this->update($data);
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class);
    }
}
