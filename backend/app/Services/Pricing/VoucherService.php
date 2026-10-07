<?php

namespace App\Services\Pricing;

use App\Exceptions\ApiException;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * IMP-002 — voucher validation + consumption.
 *
 * Server-authoritative. The frontend only ever submits a voucher CODE; the
 * value/eligibility is always re-derived here. Consumption increments
 * `used_count` under a row lock so a limited voucher cannot overspend under
 * concurrency (two checkouts applying the same final-use code: one wins, the
 * other sees used_count already at max and the order is rejected).
 */
class VoucherService
{
    /**
     * A1-06: resolve voucher EXISTENCE + scoped VALIDITY only (agent, code,
     * active window, remaining uses) — deliberately NOT cart applicability.
     * Applicability is a per-cart-line decision in OrderService (see
     * isApplicableTo), so a targeted voucher can never be rejected merely
     * because the cart contains other lines; it applies to its eligible
     * subtotal, and is rejected only when NO eligible line exists at all.
     */
    public function resolve(int $agentId, string $code): Voucher
    {
        $voucher = Voucher::query()
            ->where('agent_id', $agentId)
            ->where('code', $code)
            ->first();

        if (! $voucher || ! $voucher->isUsable()) {
            throw new ApiException(__('messages.voucher.invalid'), 422, ['voucher_code' => __('messages.voucher.invalid')]);
        }

        return $voucher;
    }

    /**
     * A1-06: does this voucher apply to ONE cart line?
     *
     *  - variation-targeted: exact product_variation_id match only.
     *  - product-targeted: any variation of that product matches (the
     *    product id is the canonical target).
     *  - whole-cart (both null): applies to every line.
     */
    public function isApplicableTo(Voucher $voucher, ?int $productId, ?int $variationId): bool
    {
        if ($voucher->product_variation_id !== null) {
            return (int) $voucher->product_variation_id === (int) $variationId;
        }

        if ($voucher->product_id !== null) {
            return (int) $voucher->product_id === (int) $productId;
        }

        return true;
    }

    /**
     * Reserve one redemption under a row lock. Returns true on success or
     * false when the voucher is no longer usable (race lost / limit hit),
     * which the caller must treat as an invalid-voucher rejection.
     */
    public function consume(int $agentId, int $voucherId): bool
    {
        $voucher = Voucher::query()
            ->where('agent_id', $agentId)
            ->whereKey($voucherId)
            ->lockForUpdate()
            ->first();

        if (! $voucher || ! $voucher->isUsable()) {
            return false;
        }

        $voucher->increment('used_count');

        return true;
    }
}