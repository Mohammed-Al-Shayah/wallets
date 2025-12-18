<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function getOrCreateUserMainWallet(User $user, ?string $currency = null): Wallet
    {
        $desiredCurrency = $currency ?: config('wallet.default_currency', 'QAR');

        return $this->createWallet($user, $desiredCurrency, Wallet::TYPE_MAIN, failIfExists: false);
    }

    public function createWallet(User $user, string $currency, string $type = Wallet::TYPE_MAIN, bool $failIfExists = true): Wallet
    {
        $normalizedCurrency = strtoupper($currency);
        $this->assertCurrencySupported($normalizedCurrency);

        $existing = Wallet::where('user_id', $user->id)
            ->where('currency', $normalizedCurrency)
            ->where('type', $type)
            ->first();

        if ($existing) {
            if ($failIfExists) {
                throw ValidationException::withMessages([
                    'currency' => ['Wallet with this currency already exists for this user.'],
                ]);
            }

            return $existing;
        }

        return Wallet::create([
            'user_id' => $user->id,
            'type'    => $type,
            'currency'=> $normalizedCurrency,
            'balance' => 0,
            'status'  => Wallet::STATUS_ACTIVE,
        ]);
    }

    public function getUserWalletOrFail(User $user, int $walletId): Wallet
    {
        $wallet = Wallet::where('id', $walletId)
            ->where('user_id', $user->id)
            ->first();

        if (! $wallet) {
            throw ValidationException::withMessages([
                'wallet_id' => ['Wallet not found for this user.'],
            ]);
        }

        return $wallet;
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
    public function transfer(Wallet $fromWallet, Wallet $toWallet, float $amount, ?string $note = null): array
    {
        if ($fromWallet->user_id === $toWallet->user_id && $fromWallet->id === $toWallet->id) {
            throw ValidationException::withMessages([
                'wallet' => ['Cannot transfer to the same wallet.'],
            ]);
        }

        if ($fromWallet->currency !== $toWallet->currency) {
            throw ValidationException::withMessages([
                'currency' => ['Transfer wallets must share the same currency.'],
            ]);
        }

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $note) {
            $reference = 'TRF-' . now()->timestamp . '-' . $fromWallet->user_id . '-' . $toWallet->user_id;

            $debitTx = $this->debit(
                wallet: $fromWallet,
                amount: $amount,
                description: 'Transfer to wallet #' . $toWallet->id,
                meta: [
                    'direction' => 'outgoing',
                    'to_user_id' => $toWallet->user_id,
                    'reference' => $reference,
                    'note'      => $note,
                ],
            );

            $creditTx = $this->credit(
                wallet: $toWallet,
                amount: $amount,
                description: 'Transfer from wallet #' . $fromWallet->id,
                meta: [
                    'direction' => 'incoming',
                    'from_user_id' => $fromWallet->user_id,
                    'reference'    => $reference,
                    'note'         => $note,
                ],
            );

            return [$debitTx, $creditTx];
        });
    }

    protected function assertCurrencySupported(string $currency): void
    {
        $supported = config('wallet.supported_currencies', []);

        if (! in_array($currency, $supported, true)) {
            throw ValidationException::withMessages([
                'currency' => ['Unsupported currency.'],
            ]);
        }
    }
}
