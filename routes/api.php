<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;

Route::prefix('v1')->group(function () {

    // ---------------- Auth Public ----------------
    Route::post('auth/register',       [AuthController::class, 'register']);
    Route::post('auth/login',          [AuthController::class, 'login']);
    Route::post('auth/verify-otp',     [AuthController::class, 'verifyOtp']);
    Route::post('auth/resend-otp',     [AuthController::class, 'resendOtp']);

    // Forgot Password — PUBLIC
    Route::post('auth/forgot-password',   [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-reset-otp',  [AuthController::class, 'verifyResetOtp']);
    Route::post('auth/reset-password',    [AuthController::class, 'resetPassword']);

    // ---------------- Auth Protected (بدون شرط active) ----------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me',        [AuthController::class, 'me']);
        Route::post('auth/logout',   [AuthController::class, 'logout']);
    });

    // ---------------- Wallets Protected (لازم user.active) ----------------
    Route::middleware(['auth:sanctum', 'user.status'])->group(function () {

    // Wallet Summary
    Route::get('wallets',               [WalletController::class, 'summary']);

    // Wallet actions
    Route::post('wallets/transfer',     [WalletController::class, 'transfer']);
    Route::post('wallets/top-up',       [WalletController::class, 'topUp']);
    Route::post('wallets/withdraw',     [WalletController::class, 'withdraw']);

    // Wallet transactions
    Route::get('wallets/transactions',  [WalletController::class, 'transactions']);

    // Developer top-up (development only)
    Route::post('wallets/top-up/dev',   [WalletController::class, 'devTopUp']);

        // 🆕 طلبات السحب الخاصة باليوزر الحالي
     Route::get('wallets/withdraw-requests', [WalletController::class, 'myWithdrawRequests']);

    // 🆕 إلغاء طلب سحب Pending
    Route::post('wallets/withdraw/{id}/cancel', [WalletController::class, 'cancelWithdrawRequest']);
});

});
