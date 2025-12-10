<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;


Route::prefix('v1')->group(function () {
    // Auth public
    Route::post('auth/register',     [AuthController::class, 'register']);
    Route::post('auth/login',        [AuthController::class, 'login']);
    Route::post('auth/verify-otp',   [AuthController::class, 'verifyOtp']);
    Route::post('auth/resend-otp',   [AuthController::class, 'resendOtp']);
        // Forgot Password — PUBLIC
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('auth/reset-password',  [AuthController::class, 'resetPassword']);


    // Auth protected (بدون شرط active)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me',        [AuthController::class, 'me']);
        Route::post('auth/logout',   [AuthController::class, 'logout']);

    });

    // Wallet protected (لازم user.active)
    Route::middleware(['auth:sanctum', 'user.status'])->group(function () {
        Route::get('wallets',                [WalletController::class, 'index']);
        Route::post('wallets/transfer',      [WalletController::class, 'transfer']);
        Route::post('wallets/top-up',        [WalletController::class, 'topUp']);
        Route::post('wallets/withdraw',      [WalletController::class, 'withdraw']);
        // ... كل العمليات المالية
    });
});
