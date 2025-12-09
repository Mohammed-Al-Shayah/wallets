<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'currency_code',
        'balance',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outgoingTransactions()
    {
        return $this->hasMany(Transaction::class, 'wallet_id_from');
    }

    public function incomingTransactions()
    {
        return $this->hasMany(Transaction::class, 'wallet_id_to');
    }
}
