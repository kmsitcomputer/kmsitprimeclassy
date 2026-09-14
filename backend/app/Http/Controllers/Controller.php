<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Resolves who the checkout/order is actually FOR — shared by
     * OrderController::store and CheckoutController::quote/courierOptions so
     * the three stay identical (never trust which is which without this
     * being consistent everywhere).
     *
     * - konsumen actor -> always themselves.
     * - agen/korsal/sales actor with no konsumen_id -> SELF-PURCHASE: they
     *   are buying for themselves (Blueprint: account role never changes
     *   just because they check out — "Account Role != Transaction Actor").
     * - agen/korsal/sales actor WITH a konsumen_id -> placing an order on
     *   behalf of that konsumen, scoped to their own branch (agent_id
     *   filter) so a cross-branch id 404s here rather than reaching
     *   OrderPolicy at all.
     *
     * Authorization (OrderPolicy::create) still runs after this — this only
     * resolves WHICH user the order is for, never whether it's allowed.
     */
    protected function resolveKonsumen(User $actor, ?int $konsumenId): User
    {
        if ($actor->isRole('konsumen')) {
            return $actor;
        }

        if (! $konsumenId) {
            return $actor;
        }

        return User::query()->where('agent_id', $actor->agent_id)->findOrFail($konsumenId);
    }

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
