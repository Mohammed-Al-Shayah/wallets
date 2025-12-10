<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // لسه مش مفعّل رقم الجوال
        if ($user->isPending() || ! $user->phone_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not verified yet. Please complete phone verification.',
                'code'    => 'ACCOUNT_NOT_VERIFIED',
            ], 403);
        }

        // محظور
        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been blocked. Please contact support.',
                'code'    => 'ACCOUNT_BLOCKED',
            ], 403);
        }

        // موقوف مؤقتًا
        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is temporarily suspended.',
                'code'    => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        return $next($request);
    }
}
