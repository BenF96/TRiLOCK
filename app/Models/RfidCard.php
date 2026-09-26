<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RfidCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'card_uid',
        'status',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Canonical UID form: uppercase hex byte pairs separated by single spaces
     * ("A4 B9 12 FD") - exactly what the ESP32 sends. Matching is an exact string compare
     * (server lookups and the lock's offline cache), so a card saved as "A4B912FD" or
     * "a4:b9:12:fd" would never be recognised. Anything that isn't a whole number of hex
     * bytes is returned trimmed/uppercased as-is.
     */
    public static function normalizeUid(?string $uid): ?string
    {
        if ($uid === null) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return strtoupper(trim($uid));
        }

        return implode(' ', str_split($hex, 2));
    }

    public function setCardUidAttribute(?string $value): void
    {
        $this->attributes['card_uid'] = self::normalizeUid($value);
    }
}
