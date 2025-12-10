<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\PhoneOtp;
use Illuminate\Support\Facades\DB;





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
        'name'              => $data['name'],
        'phone'             => $data['phone'],
        'email'             => $data['email'] ?? null,
        'password'          => Hash::make($data['password']),
        'status'            => User::STATUS_PENDING,
        'phone_verified_at' => null,

    ]);

    $otp = $this->generateOtp($user->phone, 'register', $user);

    return response()->json([
        'success' => true,
        'message' => 'Registration successful. Please verify your phone number.',
        'data'    => [
            'phone' => $user->phone,
            // ⚠ للتست فقط، بعدين شيله:
            'debug_otp' => $otp->code,
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
            'message' => 'Invalid credentials.',
            'code'    => 'INVALID_CREDENTIALS',
        ], 401);
    }

    if ($user->status !== User::STATUS_ACTIVE || ! $user->phone_verified_at) {
        // اختياري: نرسل OTP جديد لو حاب
        // $this->generateOtp($user->phone, 'register', $user);

        return response()->json([
            'success' => false,
            'message' => 'Your account is not verified yet. Please verify your phone number.',
            'code'    => 'ACCOUNT_NOT_VERIFIED',
        ], 403);
    }

    // ✅ حساب مفعّل → نولّد توكن
    $token = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful.',
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

protected function generateOtp(string $phone, string $purpose, ?User $user = null): PhoneOtp
{
    $code = random_int(100000, 999999); // 6 digits

    $otp = PhoneOtp::create([
        'user_id'    => $user?->id,
        'phone'      => $phone,
        'code'       => (string) $code,
        'purpose'    => $purpose,
        'expires_at' => now()->addMinutes(5),
    ]);

    // TODO: ربط لاحقاً مع SMS Gateway
    // حالياً لأغراض التست فقط:
    \Log::info("OTP for {$phone} ({$purpose}): {$code}");

    return $otp;
}

public function verifyOtp(Request $request)
{
    $data = $request->validate([
        'phone'   => ['required', 'string'],
        'code'    => ['required', 'string'],
        'purpose' => ['required', 'string', 'in:register'],
    ]);

    $otp = PhoneOtp::where('phone', $data['phone'])
        ->where('purpose', $data['purpose'])
        ->latest()
        ->first();

    if (! $otp) {
        return response()->json([
            'success' => false,
            'message' => 'OTP not found.',
        ], 404);
    }

    if ($otp->isUsed()) {
        return response()->json([
            'success' => false,
            'message' => 'OTP code already used.',
        ], 422);
    }

    if ($otp->isExpired()) {
        return response()->json([
            'success' => false,
            'message' => 'OTP code has expired.',
        ], 422);
    }

    if ($otp->attempts >= 5) {
        return response()->json([
            'success' => false,
            'message' => 'Too many attempts. Please request a new code.',
        ], 429);
    }

    // زيادة عدد المحاولات
    $otp->increment('attempts');

    if ($otp->code !== $data['code']) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP code.',
        ], 422);
    }

    // ✅ الكود صحيح
    $otp->update([
        'used_at' => now(),
    ]);

    $user = User::where('phone', $data['phone'])->firstOrFail();

    $user->update([
        'phone_verified_at' => now(),
        'status'            => User::STATUS_ACTIVE,
    ]);

    // إنشاء توكن
    $token = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Phone verified successfully.',
        'data'    => [
            'token' => $token,
            'user'  => $user,
        ],
    ]);
}



public function resendOtp(Request $request)
{
    $data = $request->validate([
        'phone'   => ['required', 'string'],
        'purpose' => ['required', 'string', 'in:register'],
    ]);

    $user = User::where('phone', $data['phone'])->first();

    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found.',
        ], 404);
    }

    if ($user->phone_verified_at) {
        return response()->json([
            'success' => false,
            'message' => 'Phone already verified.',
        ], 422);
    }

    // منع السبام: ما نرسل OTP جديد لو آخر واحد أقل من 60 ثانية
    $lastOtp = PhoneOtp::where('phone', $data['phone'])
        ->where('purpose', $data['purpose'])
        ->latest()
        ->first();

    if ($lastOtp && $lastOtp->created_at->gt(now()->subSeconds(60))) {
        return response()->json([
            'success' => false,
            'message' => 'Please wait before requesting a new code.',
        ], 429);
    }

    $otp = $this->generateOtp($user->phone, $data['purpose'], $user);

    return response()->json([
        'success' => true,
        'message' => 'OTP sent successfully.',
        'data'    => [
            'phone'     => $user->phone,
            // للتست فقط:
            'debug_otp' => $otp->code,
        ],
    ]);
}

public function forgotPassword(Request $request)
{
    $request->validate([
        'phone' => 'required|exists:users,phone',
    ]);

    $otp = rand(100000, 999999);

    DB::table('otps')->updateOrInsert(
        ['phone' => $request->phone, 'purpose' => 'reset_password'],
        [
            'code' => $otp,
            'expires_at' => now()->addMinutes(3)
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'OTP has been sent to your phone.',
        'expires_in' => 180
    ]);
}


public function resetPassword(Request $request)
{
    $request->validate([
        'phone' => 'required|exists:users,phone',
        'new_password' => 'required|min:6'
    ]);

    $user = User::where('phone', $request->phone)->first();
    $user->password = bcrypt($request->new_password);
    $user->save();

    DB::table('otps')
        ->where('phone', $request->phone)
        ->where('purpose', 'reset_password')
        ->delete();

    return response()->json([
        'success' => true,
        'message' => 'Password has been reset successfully.'
    ]);
}



}
