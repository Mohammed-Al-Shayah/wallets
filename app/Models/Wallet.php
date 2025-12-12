<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
        'status',
        'type',
    ];

    protected $casts = [
        'balance' => 'float',
    ];

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_BLOCKED  = 'blocked';

    public const TYPE_MAIN       = 'main';
    public const TYPE_BONUS      = 'bonus';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
