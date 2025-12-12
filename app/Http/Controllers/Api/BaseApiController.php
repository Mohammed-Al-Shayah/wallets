<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseApiController extends Controller
{
    /**
     * Return a standardized success JSON response.
     */
    protected function success(
        string $message = 'OK',
        string $code = 'SUCCESS',
        mixed $data = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'code'    => $code,
            'data'    => $data,
        ], $status);
    }

    /**
     * Return a standardized error JSON response.
     */
    protected function error(
        string $message = 'Error',
        string $code = 'ERROR',
        int $status = 400,
        ?array $errors = null
    ): JsonResponse {
        $payload = [
            'status'  => false,
            'message' => $message,
            'code'    => $code,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
