<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessPin extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pin_code',
        'offline_hash',
        'status',
        'assigned_at',
    ];

    protected $hidden = [
        'pin_code',     // Never expose the bcrypt hash
        'offline_hash', // Only ever sent to devices via the authenticated offline-sync endpoint
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
