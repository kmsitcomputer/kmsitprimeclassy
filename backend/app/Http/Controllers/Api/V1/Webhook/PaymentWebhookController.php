<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentService;
use App\Support\SafeSchema;
use Illuminate\Http\Request;

/**
 * Public endpoint (no Sanctum session — gateways can't carry one) reached at
 * /api/v1/webhooks/payment/{method}. The ONLY thing that authorizes a
 * request here is PaymentService::handleWebhook's signature check; nothing
 * about who called this is ever inferred from request data.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function handle(Request $request, string $method)
    {
        // A gateway can fire this before the app is even installed (or
        // during the installer wizard's own reachability window) — no
        // `payment_methods` table means no configured gateway either way,
        // so this is the same clean "unknown method" 404 a real lookup miss
        // would give, never a raw QueryException (see SafeSchema's docblock).
        if (! SafeSchema::hasTable('payment_methods')) {
            return $this->fail('Unknown payment method.', null, 404);
        }

        $paymentMethod = PaymentMethod::query()->where('code', $method)->where('type', 'gateway')->first();

        if (! $paymentMethod) {
            return $this->fail('Unknown payment method.', null, 404);
        }

        $result = $this->paymentService->handleWebhook($paymentMethod, $request);

        $status = match ($result['status']) {
            'invalid_signature' => 401,
            'unknown_reference' => 422,
            default => 200,
        };

        return $status === 200
            ? $this->ok(null, $result['message'])
            : $this->fail($result['message'], null, $status);
    }
}
