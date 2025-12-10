<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_BLOCKED   = 'blocked';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'status',
        'phone_verified_at',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
    ];

    // Helpers
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

        public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }
}
