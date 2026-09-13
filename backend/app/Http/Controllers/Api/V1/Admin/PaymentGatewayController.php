<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Logging\ActivityLogger;

/**
 * Super Admin-only payment method administration — GLOBAL on/off switch
 * only. Credentials (bank account details, gateway API keys) are configured
 * per-agen instead (see Agent\AgentPaymentMethodController) — super_admin
 * never sees or sets them.
 */
class PaymentGatewayController extends Controller
{
    public function index()
    {
        $methods = PaymentMethod::query()->orderBy('id')->get();

        return $this->ok($methods->map(fn (PaymentMethod $method) => $this->present($method))->values());
    }

    public function toggle(PaymentMethod $method)
    {
        $method->update(['is_active' => ! $method->is_active]);

        ActivityLogger::log(request()->user()->id, $method, 'payment_gateway.toggled', null, ['is_active' => $method->is_active]);

        return $this->ok($this->present($method->fresh()), __('messages.payment.gateway_toggled'));
    }

    private function present(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'type' => $method->type,
            'is_active' => $method->is_active,
            'webhook_url' => $method->type === 'gateway'
                ? url("/api/v1/webhooks/payment/{$method->code}")
                : null,
        ];
    }
}
