<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_student_id',
        'first_name',
        'middle_name',
        'last_name',
        'photo',
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getFullNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
        }
        return $this->name ?? 'Unknown User';
    }

    /**
     * Public URL for the user's photo, resolved against whichever disk
     * FILESYSTEM_DISK points to (local for a single instance, S3 when
     * running multiple app instances behind a load balancer).
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo) {
            return null;
        }

        if (str_starts_with($this->photo, 'http://') || str_starts_with($this->photo, 'https://')) {
            return $this->photo;
        }

        return \Illuminate\Support\Facades\Storage::url($this->photo);
    }

    public function rfidCards()
    {
        return $this->hasMany(RfidCard::class);
    }

    public function activeRfidCard()
    {
        return $this->hasOne(RfidCard::class)->where('status', 'active');
    }

    public function fingerprints()
    {
        return $this->hasMany(Fingerprint::class);
    }

    public function activeFingerprint()
    {
        return $this->hasOne(Fingerprint::class)->where('status', 'active');
    }

    public function accessPins()
    {
        return $this->hasMany(AccessPin::class);
    }

    public function activePin()
    {
        return $this->hasOne(AccessPin::class)->where('status', 'active');
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class);
    }
}
