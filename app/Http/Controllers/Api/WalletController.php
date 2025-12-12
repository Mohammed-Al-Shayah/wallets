<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Transaction;


use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletController extends BaseApiController
{
    public function __construct(
        protected WalletService $walletService,
    ) {
    }

    /**
     * GET /wallet
     * ملخّص محفظة المستخدم الحالي
     */
    public function summary(Request $request)
    {
        $user   = $request->user();
        $wallet = $this->walletService->getOrCreateUserMainWallet($user);

        return $this->success(
            message: 'Wallet summary.',
            code: 'WALLET_SUMMARY',
            data: [
                'balance'  => $wallet->balance,
                'currency' => $wallet->currency,
                'status'   => $wallet->status,
                'type'     => $wallet->type,
            ],
        );
    }

    /**
     * GET /wallet/transactions
     */
    public function transactions(Request $request)
    {
        $user   = $request->user();
        $wallet = $this->walletService->getOrCreateUserMainWallet($user);

        $transactions = $wallet->transactions()
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Wallet transactions.',
            code: 'WALLET_TRANSACTIONS',
            data: $transactions,
        );
    }

    /**
     * POST /wallet/transfer
     * body: { "to_phone": "059...", "amount": 50, "note": "..." }
     */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'to_phone' => ['required', 'string', 'exists:users,phone'],
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $fromUser */
        $fromUser = $request->user();
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
                fromUser: $fromUser,
                toUser: $toUser,
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
        ]);

        $user   = $request->user();
        $wallet = $this->walletService->getOrCreateUserMainWallet($user);

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
        $user = $request->user();

        $wallet = $user->wallets()->firstOrFail(); // أو حسب علاقتك الحالية

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

    // من الأفضل ربطها بالمحفظة الخاصة باليوزر عشان الأمان
    $wallet = $user->wallets()->firstOrFail(); // عدّل لو عندك أكتر من محفظة

    /** @var Transaction|null $tx */
    $tx = Transaction::query()
        ->where('id', $id)
        ->where('wallet_id', $wallet->id)
        ->where('type', 'withdraw')
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

}
