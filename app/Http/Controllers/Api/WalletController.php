<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Transaction;


use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class WalletController extends BaseApiController
{
    public function __construct(
        protected WalletService $walletService,
    ) {
    }

    /**
     * POST /wallets/top-up/quote
     */
    public function topUpQuote(Request $request)
    {
        $data = $request->validate([
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'amount'    => ['required', 'numeric', 'min:0.01'],
        ]);

        $user = $request->user();
        $wallet = isset($data['wallet_id'])
            ? $this->walletService->getUserWalletOrFail($user, (int) $data['wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($user);

        $amount = (float) $data['amount'];
        $fee    = $this->calculateTopUpFee($amount);

        return $this->success(
            message: 'Top-up quote.',
            code: 'TOPUP_QUOTE',
            data: [
                'wallet_id' => $wallet->id,
                'currency'  => $wallet->currency,
                'amount'    => $amount,
                'fee'       => $fee,
                'total'     => $amount + $fee,
                'limits'    => [
                    'min' => 0.01,
                    'max' => null,
                ],
            ],
        );
    }

    /**
     * POST /wallets/top-up
     */
    public function topUp(Request $request)
    {
        $data = $request->validate([
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'note'      => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $wallet = isset($data['wallet_id'])
            ? $this->walletService->getUserWalletOrFail($user, (int) $data['wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($user);

        $amount = (float) $data['amount'];
        $fee    = $this->calculateTopUpFee($amount);
        $total  = $amount + $fee;

        $reference = 'TPU-' . now()->timestamp . '-' . $wallet->id . '-' . Str::random(6);

        $transaction = Transaction::create([
            'user_id'        => $user->id,
            'wallet_id'      => $wallet->id,
            'type'           => Transaction::TYPE_CREDIT,
            'amount'         => $amount,
            'fee'            => $fee,
            'total_amount'   => $total,
            'balance_before' => $wallet->balance,
            'balance_after'  => $wallet->balance,
            'status'         => Transaction::STATUS_PENDING,
            'reference'      => $reference,
            'description'    => 'Top-up initiation',
            'meta'           => [
                'source' => 'card_topup',
                'note'   => $data['note'] ?? null,
            ],
        ]);

        $paymentUrl = rtrim(config('wallet.topup_payment_url'), '/') . '?reference=' . urlencode($reference);

        return $this->success(
            message: 'Top-up initiated. Redirect to payment gateway.',
            code: 'TOPUP_INITIATED',
            data: [
                'wallet'       => $wallet,
                'transaction'  => $transaction,
                'payment_url'  => $paymentUrl,
            ],
            status: 201,
        );
    }

    /**
     * Webhook from payment gateway to confirm top-up.
     */
    public function topUpWebhook(Request $request)
    {
        $token = $request->header('X-Webhook-Token');
        if ($token !== config('wallet.topup_webhook_token')) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid webhook token.',
            ], 403);
        }

        $data = $request->validate([
            'reference' => ['required', 'string'],
            'status'    => ['required', Rule::in(['success', 'failed'])],
        ]);

        /** @var Transaction|null $transaction */
        $transaction = Transaction::query()
            ->where('reference', $data['reference'])
            ->where('type', Transaction::TYPE_CREDIT)
            ->first();

        if (! $transaction) {
            return response()->json([
                'status'  => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        if ($transaction->status !== Transaction::STATUS_PENDING) {
            return response()->json([
                'status'  => true,
                'message' => 'Transaction already processed.',
                'data'    => $transaction,
            ]);
        }

        if ($data['status'] === 'failed') {
            $transaction->status = Transaction::STATUS_FAILED;
            $transaction->description = 'Top-up failed';
            $transaction->meta = array_merge($transaction->meta ?? [], ['webhook_status' => 'failed']);
            $transaction->save();

            return response()->json([
                'status'  => true,
                'message' => 'Top-up marked as failed.',
                'data'    => $transaction,
            ]);
        }

        DB::transaction(function () use ($transaction) {
            $wallet = $transaction->wallet()->lockForUpdate()->first();

            $before = $wallet->balance;
            $after  = $before + $transaction->amount;

            $wallet->balance = $after;
            $wallet->save();

            $transaction->status = Transaction::STATUS_COMPLETED;
            $transaction->balance_before = $before;
            $transaction->balance_after  = $after;
            $transaction->description = 'Top-up successful';
            $transaction->meta = array_merge($transaction->meta ?? [], ['webhook_status' => 'success']);
            $transaction->save();
        });

        return response()->json([
            'status'  => true,
            'message' => 'Top-up completed.',
        ]);
    }

    /**
     * GET /wallet
     * ملخّص محفظة المستخدم الحالي
     */
    public function summary(Request $request)
    {
        $user   = $request->user();
        $defaultWallet = $this->walletService->getOrCreateUserMainWallet($user);

        $walletsQuery = $user->wallets()->orderBy('id');

        if ($request->query('currency')) {
            $walletsQuery->where('currency', strtoupper($request->query('currency')));
        }

        $wallets = $walletsQuery->get();

        $currencyNames = [
            'USD' => 'US Dollar',
            'ILS' => 'Israeli Shekel',
            'QAR' => 'Qatari Riyal',
            'EUR' => 'Euro',
            'JOD' => 'Jordanian Dinar',
            'EGP' => 'Egyptian Pound',
        ];

        foreach ($wallets as $wallet) {
            $code = strtoupper($wallet->currency);
            $wallet->currency_name = $currencyNames[$code] ?? $code;
        }

        $targetCurrency = 'USD';
        $walletCurrencies = $wallets
            ->pluck('currency')
            ->map(fn ($currency) => strtoupper($currency))
            ->unique()
            ->values();

        $lookupCurrencies = $walletCurrencies
            ->filter(fn ($currency) => $currency !== $targetCurrency)
            ->values();

        $directRates = DB::table('exchange_rates')
            ->where('to_currency', $targetCurrency)
            ->whereIn('from_currency', $lookupCurrencies)
            ->get()
            ->keyBy('from_currency');

        $inverseRates = DB::table('exchange_rates')
            ->where('from_currency', $targetCurrency)
            ->whereIn('to_currency', $lookupCurrencies)
            ->get()
            ->keyBy('to_currency');

        $totalBalanceUsd = 0.0;
        $missingCurrencies = [];

        foreach ($wallets as $wallet) {
            $currency = strtoupper($wallet->currency);

            if ($currency === $targetCurrency) {
                $rate = 1.0;
            } elseif ($directRates->has($currency)) {
                $rate = (float) $directRates->get($currency)->rate;
            } elseif ($inverseRates->has($currency)) {
                $inverseRate = (float) $inverseRates->get($currency)->rate;
                $rate = $inverseRate > 0 ? 1 / $inverseRate : null;
            } else {
                $rate = null;
            }

            if ($rate === null) {
                $missingCurrencies[] = $currency;
                continue;
            }

            $totalBalanceUsd += (float) $wallet->balance * $rate;
        }

        if (! empty($missingCurrencies)) {
            $missingCurrencies = array_values(array_unique($missingCurrencies));

            return $this->error(
                message: 'Missing exchange rates for some currencies.',
                code: 'EXCHANGE_RATE_MISSING',
                status: 422,
                errors: ['currencies' => $missingCurrencies],
            );
        }

        $walletIds = $wallets->pluck('id')->all();
        $monthStart = now()->startOfMonth();
        $monthlyTransfersUsd = 0.0;

        if (! empty($walletIds)) {
            $lastIncomingSub = DB::table('transactions')
                ->select('wallet_id', DB::raw('MAX(id) as last_id'))
                ->where('transactions.user_id', $user->id)
                ->whereIn('transactions.wallet_id', $walletIds)
                ->where('transactions.reference', 'like', 'TRF-%')
                ->where('transactions.type', 'credit')
                ->where('transactions.meta->direction', 'incoming')
                ->groupBy('wallet_id');

            $lastIncomingTransfers = DB::table('transactions as t')
                ->joinSub($lastIncomingSub, 'li', function ($join) {
                    $join->on('t.id', '=', 'li.last_id');
                })
                ->select('t.id', 't.wallet_id', 't.amount', 't.reference', 't.description', 't.created_at')
                ->get()
                ->keyBy('wallet_id');

            foreach ($wallets as $wallet) {
                $wallet->last_incoming_transfer = $lastIncomingTransfers->get($wallet->id);
            }

            $transferTotals = DB::table('transactions')
                ->join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
                ->where('transactions.user_id', $user->id)
                ->whereIn('transactions.wallet_id', $walletIds)
                ->where('transactions.reference', 'like', 'TRF-%')
                ->where('transactions.created_at', '>=', $monthStart)
                ->select('wallets.currency', DB::raw('SUM(transactions.amount) as total_amount'))
                ->groupBy('wallets.currency')
                ->get();

            foreach ($transferTotals as $row) {
                $currency = strtoupper($row->currency);
                $amount = (float) $row->total_amount;

                if ($currency === $targetCurrency) {
                    $rate = 1.0;
                } elseif ($directRates->has($currency)) {
                    $rate = (float) $directRates->get($currency)->rate;
                } elseif ($inverseRates->has($currency)) {
                    $inverseRate = (float) $inverseRates->get($currency)->rate;
                    $rate = $inverseRate > 0 ? 1 / $inverseRate : null;
                } else {
                    $rate = null;
                }

                if ($rate === null) {
                    continue;
                }

                $monthlyTransfersUsd += $amount * $rate;
            }
        }

        return $this->success(
            message: 'Wallet summary.',
            code: 'WALLET_SUMMARY',
            data: [
                'wallets'           => $wallets,
                'default_wallet_id' => $defaultWallet->id,
                'total_balance_usd' => $totalBalanceUsd,
                'monthly_transfers_usd' => $monthlyTransfersUsd,
            ],
        );
    }

    /**
     * GET /wallets/{wallet}
     * تفاصيل محفظة واحدة
     */
    public function show(Request $request, Wallet $wallet)
    {
        if ($wallet->user_id !== $request->user()->id) {
            return $this->error(
                message: 'Wallet not found.',
                code: 'WALLET_NOT_FOUND',
                status: 404,
            );
        }

        return $this->success(
            message: 'Wallet details.',
            code: 'WALLET_DETAIL',
            data: $wallet,
        );
    }

    /**
     * POST /wallets
     * إنشاء محفظة جديدة بعملة معينة
     */
    public function create(Request $request)
    {
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(config('wallet.supported_currencies', []))],
            'type'     => ['nullable', 'string', Rule::in([Wallet::TYPE_MAIN, Wallet::TYPE_BONUS, Wallet::TYPE_SAVING])],
        ]);

        $user = $request->user();
        $type = $data['type'] ?? Wallet::TYPE_MAIN;

        try {
            $wallet = $this->walletService->createWallet(
                user: $user,
                currency: $data['currency'],
                type: $type,
                failIfExists: true,
            );
        } catch (ValidationException $e) {
            return $this->error(
                message: 'Wallet creation failed.',
                code: 'WALLET_CREATE_FAILED',
                status: 422,
                errors: $e->errors(),
            );
        }

        return $this->success(
            message: 'Wallet created.',
            code: 'WALLET_CREATED',
            data: $wallet,
            status: 201,
        );
    }

    /**
     * GET /wallet/transactions
     */
    public function transactions(Request $request)
    {
        $data = $request->validate([
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
        ]);

        $user   = $request->user();
        $wallet = isset($data['wallet_id'])
            ? $this->walletService->getUserWalletOrFail($user, (int) $data['wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($user);

        $transactions = $wallet->transactions()
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Wallet transactions.',
            code: 'WALLET_TRANSACTIONS',
            data: [
                'wallet'        => $wallet,
                'transactions'  => $transactions,
            ],
        );
    }

    /**
     * POST /wallet/transfer
     * body: { "to_phone": "059...", "amount": 50, "note": "..." }
     */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'to_phone' => ['required', 'string', 'exists:users,phone'],
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $fromUser */
        $fromUser = $request->user();
        $fromWallet = isset($data['from_wallet_id'])
            ? $this->walletService->getUserWalletOrFail($fromUser, (int) $data['from_wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($fromUser);
        $toUser   = User::where('phone', $data['to_phone'])->first();

        if (! $toUser) {
            return $this->error(
                message: 'Recipient user not found.',
                code: 'RECIPIENT_NOT_FOUND',
                status: 404,
            );
        }

        try {
            [$debitTx, $creditTx] = $this->walletService->transfer(
                fromWallet: $fromWallet,
                toWallet: $this->walletService->getOrCreateUserMainWallet($toUser, $fromWallet->currency),
                amount: (float) $data['amount'],
                note: $data['note'] ?? null,
            );
        } catch (ValidationException $e) {
            // مثلاً "Insufficient balance" أو "Cannot transfer to same user"
            return $this->error(
                message: 'Transfer failed.',
                code: 'TRANSFER_FAILED',
                status: 422,
                errors: $e->errors(),
            );
        }

        return $this->success(
            message: 'Transfer completed successfully.',
            code: 'TRANSFER_OK',
            data: [
                'debit_transaction'  => $debitTx,
                'credit_transaction' => $creditTx,
            ],
        );
    }

    /**
     * POST /wallet/topup/dev
     * شحن تجريبي للمطور (بدون بوابة دفع)
     */
    public function devTopUp(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
        ]);

        $user   = $request->user();
        $wallet = isset($data['wallet_id'])
            ? $this->walletService->getUserWalletOrFail($user, (int) $data['wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($user);

        $tx = $this->walletService->credit(
            wallet: $wallet,
            amount: (float) $data['amount'],
            description: 'Developer top-up (no real payment)',
            meta: ['source' => 'dev_topup'],
        );

        return $this->success(
            message: 'Wallet topped up (dev mode).',
            code: 'TOPUP_DEV_OK',
            data: [
                'wallet'      => $wallet->fresh(),
                'transaction' => $tx,
            ],
        );
    }

    public function withdraw(Request $request)
{
    $request->validate([
        'wallet_id' => 'required|exists:wallets,id',
        'amount' => 'required|numeric|min:1',
    ]);

    $wallet = Wallet::where('id', $request->wallet_id)
        ->where('user_id', auth()->id())
        ->first();

    if (! $wallet) {
        return response()->json([
            'success' => false,
            'message' => 'Wallet not found.',
        ], 404);
    }

    // Check balance
    if ($wallet->balance < $request->amount) {
        return response()->json([
            'success' => false,
            'message' => 'Insufficient wallet balance.',
            'current_balance' => $wallet->balance,
        ], 422);
    }

    // Deduct balance
    $wallet->balance -= $request->amount;
    $wallet->save();

    // (اختياري) سجل الحركة
    Transaction::create([
        'user_id'   => auth()->id(),
        'wallet_id' => $wallet->id,
        'type'      => 'withdraw',
        'amount'    => $request->amount,
        'balance_after' => $wallet->balance,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Withdrawal successful.',
        'wallet_balance' => $wallet->balance,
    ]);
}

 public function myWithdrawRequests(Request $request)
    {
        $data = $request->validate([
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
        ]);

        $user = $request->user();

        $wallet = isset($data['wallet_id'])
            ? $this->walletService->getUserWalletOrFail($user, (int) $data['wallet_id'])
            : $this->walletService->getOrCreateUserMainWallet($user);

        $transactions = Transaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'withdraw')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'status'  => true,
            'message' => 'Withdraw requests list.',
            'code'    => 'WITHDRAW_REQUESTS',
            'data'    => $transactions,
        ]);
    }

    public function cancelWithdrawRequest(Request $request, int $id)
{
    $user = $request->user();

    /** @var Transaction|null $tx */
    $tx = Transaction::query()
        ->where('id', $id)
        ->where('type', 'withdraw')
        ->whereHas('wallet', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->first();

    if (! $tx) {
        return response()->json([
            'status'  => false,
            'message' => 'Withdraw request not found.',
            'code'    => 'WITHDRAW_NOT_FOUND',
        ], 404);
    }

    if ($tx->status !== 'pending') {
        return response()->json([
            'status'  => false,
            'message' => 'Only pending withdraw requests can be canceled.',
            'code'    => 'WITHDRAW_NOT_PENDING',
        ], 422);
    }

    $tx->status = 'rejected';     // أو 'canceled' لو حاب تضيفها كحالة في DB
    $tx->description = 'Canceled by user';
    $tx->save();

    return response()->json([
        'status'  => true,
        'message' => 'Withdraw request canceled successfully.',
        'code'    => 'WITHDRAW_CANCELED',
        'data'    => $tx,
    ]);
}

    private function calculateTopUpFee(float $amount): float
    {
        $percent = (float) config('wallet.topup_fee_percent', 0);
        $flat    = (float) config('wallet.topup_fee_flat', 0);

        $fee = ($percent / 100) * $amount + $flat;

        return round($fee, 2);
    }

}
