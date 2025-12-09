<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $walletService) {}

    public function index(Request $request)
    {
        $wallets = $request->user()->wallets()
            ->select('id', 'currency_code', 'balance', 'status')
            ->get();

        return response()->json($wallets);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'to_phone' => ['required', 'string'],
            'amount'   => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $tx = $this->walletService->transferInternal(
            $request->user(),
            $data['to_phone'],
            (float) $data['amount'],
            $data['currency'],
        );

        return response()->json([
            'transaction_id' => $tx->id,
            'status'         => $tx->status,
        ]);
    }
}
