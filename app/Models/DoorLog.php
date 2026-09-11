<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoorLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',      // 'unlocked' | 'locked' | 'opened' | 'closed' | 'left_open'
        'opened_at',
        'closed_at',
        // locked_at was missing here, so every DoorLog::create([..., 'locked_at' => ...])
        // call in the codebase (lockout/denial events, and the solenoid relock event)
        // was silently dropping it via Eloquent's mass-assignment guard - the "Locked At"
        // column has never actually been populated.
        'locked_at',
        'remarks',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];
}
