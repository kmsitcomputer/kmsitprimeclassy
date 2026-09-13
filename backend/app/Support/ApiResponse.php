<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Single source of truth for the API's response envelope:
 * { success, message, data, errors, meta } — every endpoint returns this shape.
 */
class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        ?array $meta = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
            'meta' => $meta,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, null, 201);
    }

    public static function error(
        string $message,
        ?array $errors = null,
        int $status = 422
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'meta' => null,
        ], $status);
    }
}
