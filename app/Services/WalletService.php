<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function transferInternal(User $sender, string $toPhone, float $amount, string $currency): Transaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($sender, $toPhone, $amount, $currency) {
            $currency = strtoupper($currency);

            // 1) sender wallet
            $fromWallet = Wallet::where('user_id', $sender->id)
                ->where('currency_code', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fromWallet->balance < $amount) {
                throw ValidationException::withMessages([
                    'balance' => 'Insufficient balance.',
                ]);
            }

            // 2) receiver
            $receiver = User::where('phone', $toPhone)->firstOrFail();

            $toWallet = Wallet::firstOrCreate(
                [
                    'user_id'       => $receiver->id,
                    'currency_code' => $currency,
                ],
                [
                    'balance' => 0,
                    'status'  => 'active',
                ]
            );

            $toWallet->refresh();
            $toWallet->lockForUpdate();

            // 3) update balances
            $fromWallet->balance -= $amount;
            $fromWallet->save();

            $toWallet->balance += $amount;
            $toWallet->save();

            // 4) transaction record
            return Transaction::create([
                'user_id'        => $sender->id,
                'wallet_id_from' => $fromWallet->id,
                'wallet_id_to'   => $toWallet->id,
                'type'           => 'transfer',
                'amount'         => $amount,
                'fee'            => 0,
                'total_amount'   => $amount,
                'currency_code'  => $currency,
                'status'         => 'success',
                'reference'      => null,
                'meta'           => [
                    'to_phone'  => $toPhone,
                    'direction' => 'internal_transfer',
                ],
            ]);
        });
    }
}
