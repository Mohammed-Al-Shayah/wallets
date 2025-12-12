<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function getOrCreateUserMainWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            [
                'user_id' => $user->id,
                'type'    => Wallet::TYPE_MAIN,
            ],
            [
                'balance' => 0,
                'currency'=> 'QRA', // عدّلها لو بدك SAR أو غيره
                'status'  => Wallet::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * إيداع داخلي (مثلاً bonus / شحن تجريبي)
     */
    public function credit(Wallet $wallet, float $amount, string $description = null, array $meta = []): Transaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be greater than zero.'],
            ]);
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $meta) {
            $wallet->refresh();

            $before = $wallet->balance;
            $after  = $wallet->balance + $amount;

            $wallet->balance = $after;
            $wallet->save();

            return Transaction::create([
                'user_id'        => $wallet->user_id,
                'wallet_id'      => $wallet->id,
                'type'           => Transaction::TYPE_CREDIT,
                'amount'         => $amount,
                'fee'            => 0,
                'total_amount'   => $amount,
                'balance_before' => $before,
                'balance_after'  => $after,
                'status'         => Transaction::STATUS_COMPLETED,
                'description'    => $description,
                'reference'      => $meta['reference'] ?? null,
                'meta'           => $meta,
            ]);
        });
    }

    /**
     * خصم داخلي
     */
    public function debit(Wallet $wallet, float $amount, string $description = null, array $meta = []): Transaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be greater than zero.'],
            ]);
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $meta) {
            $wallet->refresh();

            if ($wallet->balance < $amount) {
                throw ValidationException::withMessages([
                    'balance' => ['Insufficient balance.'],
                ]);
            }

            $before = $wallet->balance;
            $after  = $wallet->balance - $amount;

            $wallet->balance = $after;
            $wallet->save();

            return Transaction::create([
                'user_id'        => $wallet->user_id,
                'wallet_id'      => $wallet->id,
                'type'           => Transaction::TYPE_DEBIT,
                'amount'         => $amount,
                'fee'            => 0,
                'total_amount'   => $amount,
                'balance_before' => $before,
                'balance_after'  => $after,
                'status'         => Transaction::STATUS_COMPLETED,
                'description'    => $description,
                'reference'      => $meta['reference'] ?? null,
                'meta'           => $meta,
            ]);
        });
    }

    /**
     * تحويل من يوزر ليوزر (داخلي)
     */
    public function transfer(User $fromUser, User $toUser, float $amount, ?string $note = null): array
    {
        if ($fromUser->id === $toUser->id) {
            throw ValidationException::withMessages([
                'to_user' => ['Cannot transfer to the same user.'],
            ]);
        }

        $fromWallet = $this->getOrCreateUserMainWallet($fromUser);
        $toWallet   = $this->getOrCreateUserMainWallet($toUser);

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $note, $fromUser, $toUser) {
            $reference = 'TRF-' . now()->timestamp . '-' . $fromUser->id . '-' . $toUser->id;

            $debitTx = $this->debit(
                wallet: $fromWallet,
                amount: $amount,
                description: 'Transfer to ' . $toUser->phone,
                meta: [
                    'direction' => 'outgoing',
                    'to_user_id' => $toUser->id,
                    'reference' => $reference,
                    'note'      => $note,
                ],
            );

            $creditTx = $this->credit(
                wallet: $toWallet,
                amount: $amount,
                description: 'Transfer from ' . $fromUser->phone,
                meta: [
                    'direction' => 'incoming',
                    'from_user_id' => $fromUser->id,
                    'reference'    => $reference,
                    'note'         => $note,
                ],
            );

            return [$debitTx, $creditTx];
        });
    }
}
