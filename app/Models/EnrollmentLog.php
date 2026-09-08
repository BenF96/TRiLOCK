<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnrollmentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'enrolled_user_id',
        'session_token',
        'step',
        'status',
        'payload',
        'remarks',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function enrolledUser()
    {
        return $this->belongsTo(User::class, 'enrolled_user_id');
    }
}
