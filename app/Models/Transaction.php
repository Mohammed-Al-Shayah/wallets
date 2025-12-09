<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wallet_id_from',
        'wallet_id_to',
        'type',
        'amount',
        'fee',
        'total_amount',
        'currency_code',
        'status',
        'reference',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fromWallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id_from');
    }

    public function toWallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id_to');
    }
}
