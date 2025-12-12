<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',              // credit / debit
        'amount',
        'fee',
        'total_amount',
        'balance_before',
        'balance_after',
        'status',            // pending / completed / failed
        'reference',         // رقم مرجعي داخلي / خارجي
        'description',
        'meta',
    ];

    protected $casts = [
       'amount'          => 'float',
       'balance_before'  => 'float',
       'balance_after'   => 'float',
       'meta'            => 'array',
    ];

     const TYPE_WITHDRAW = 'withdraw';

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED  = 'rejected';

    public function scopeWithdraw($query)
    {
        return $query->where('type', self::TYPE_WITHDRAW);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isWithdraw(): bool
    {
        return $this->type === self::TYPE_WITHDRAW;
    }

    public const TYPE_CREDIT   = 'credit';
    public const TYPE_DEBIT    = 'debit';

    public const STATUS_FAILED    = 'failed';

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
