<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;



class AuthController extends Controller
{
   public function register(Request $request)
{
    $data = $request->validate([
        'name'     => ['required', 'string', 'max:255'],
        'phone'    => ['required', 'string', 'max:20', 'unique:users,phone'],
        'email'    => ['nullable', 'email', 'unique:users,email'],
        'password' => ['required', 'string', 'min:6'],
    ]);

    $user = User::create([
        'name'     => $data['name'],
        'phone'    => $data['phone'],
        'email'    => $data['email'] ?? null,
        'password' => Hash::make($data['password']),
        'status'   => 'active',
    ]);

    $wallet = Wallet::create([
        'user_id'       => $user->id,
        'currency_code' => 'SAR',
        'balance'       => 0,
        'status'        => 'active',
    ]);

    $token = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Registration successful',
        'data'    => [
            'token'  => $token,
            'user'   => $user,
            'wallet' => $wallet,
        ],
    ], 201);
}

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

      if (! $user || ! Hash::check($data['password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid phone or password',
            'errors'  => [
                'phone' => ['The provided credentials are incorrect.'],
            ],
        ], 422);
    }

  
    $token = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'data'    => [
            'token' => $token,
            'user'  => $user,
        ],
    ]);
    }

    public function me(Request $request)
{
    $user = $request->user(); // من التوكن

    return response()->json([
        'success' => true,
        'message' => 'User profile fetched successfully',
        'data'    => [
            'user' => $user,
        ],
    ]);
}

public function logout(Request $request)
{
    $user = $request->user();

    $user->currentAccessToken()->delete();

    return response()->json([
        'success' => true,
        'message' => 'Logged out successfully',
    ]);
}

}
