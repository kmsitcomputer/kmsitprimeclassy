<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function ok(mixed $data = null, string $message = 'OK', ?array $meta = null): JsonResponse
    {
        return ApiResponse::success($data, $message, $meta, 200);
    }

    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return ApiResponse::created($data, $message);
    }

    protected function fail(string $message, ?array $errors = null, int $status = 422): JsonResponse
    {
        return ApiResponse::error($message, $errors, $status);
    }
}
