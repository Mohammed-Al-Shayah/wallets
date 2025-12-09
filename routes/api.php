<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;

Route::prefix('v1')->group(function () {

    // Auth
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('wallets', [WalletController::class, 'index']);
        Route::post('wallets/transfer', [WalletController::class, 'transfer']);
    });
});
