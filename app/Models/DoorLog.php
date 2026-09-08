<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoorLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',      // 'opened' | 'closed'
        'opened_at',
        'closed_at',
        'remarks',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
