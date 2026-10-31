<?php

namespace App\Services\Payment;

use App\Models\AgentPaymentMethodSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Canonical "which payment methods can this order actually use" rule —
 * Agent-enabled methods ∩ shipping-method compatibility. Reused by
 * `CheckoutStepResolver` (UX: what the wizard offers) and `OrderService`
 * (authoritative: what order creation actually accepts) so the two never
 * diverge. Never re-implement this filter inline anywhere else.
 *
 * Compatibility rule (Blueprint): Ekspedisi (RajaOngkir) only ever allows
 * Manual Transfer — COD/DP/Payment Gateway are excluded because an
 * expedition courier is a third party the agent doesn't control, so COD
 * cash-on-delivery and DP's own-courier-collects-the-balance assumptions
 * don't hold. Kurir Online (OpenRoute) and any other shipping method keep
 * the agent's own configuration unrestricted.
 */
class AvailablePaymentMethodService
{
    /** Payment method codes allowed when shipping_method = 'rajaongkir' (Ekspedisi). */
    private const EXPEDITION_ALLOWED_CODES = ['bank_transfer'];

    /**
     * Owner rule (Improvement 04): a konsumen checking out for themselves only ever sees full Bank
     * Transfer — no COD and no DP. Staff roles keep COD + Bank Transfer (+ DP as a Transfer sub-option).
     */
    private const KONSUMEN_ALLOWED_CODES = ['bank_transfer'];

    /** @return Collection<int, PaymentMethod> */
    public function availableMethods(?int $agentId, ?string $shippingMethod, ?User $actor = null): Collection
    {
        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->where('type', '!=', 'gateway')
            ->when($agentId, fn ($q) => $q->whereNotIn('id', AgentPaymentMethodSetting::query()
                ->where('agent_id', $agentId)->where('is_active', false)->pluck('payment_method_id')))
            ->orderBy('id')
            ->get();

        if ($actor?->isRole('konsumen')) {
            $methods = $methods->whereIn('code', self::KONSUMEN_ALLOWED_CODES)->values();
        }

        if ($shippingMethod === 'rajaongkir') {
            return $methods->whereIn('code', self::EXPEDITION_ALLOWED_CODES)->values();
        }

        return $methods;
    }

    public function isAvailable(?int $agentId, ?string $shippingMethod, PaymentMethod $method, ?User $actor = null): bool
    {
        return $this->availableMethods($agentId, $shippingMethod, $actor)->contains('id', $method->id);
    }
}
