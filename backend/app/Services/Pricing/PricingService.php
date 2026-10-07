<?php

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\ProductDiscount;
use App\Models\ProductVariation;
use App\Models\Voucher;

/**
 * IMP-002 — canonical line/item pricing with discounts + vouchers.
 *
 * Single source of truth for "what price does this product/variation line
 * actually cost right now". Consumed by OrderService::quoteLine (preview) and
 * priceAndReserveLine (actual creation), so the preview and the persisted
 * price can never diverge. Historical orders keep their
 * `unit_price_snapshot` — this service is only ever invoked at quote/order-
 * creation time, never on existing orders.
 *
 * Rules enforced here (server-authoritative, never client):
 *  - discount percentage is clamped to [0,100], never exceeding 100% of base.
 *  - a voucher cannot drive the effective unit price below 0 (clamped to 0).
 *  - inactive / out-of-validity-window rows never apply (ProductDiscount/
 *    Voucher::isCurrentlyValid / isUsable).
 *  - cross-agent mismatches are rejected by the caller (controllers/services
 *    scope by agent_id; this service only receives already-scoped rows).
 */
class PricingService
{
    /** The most specific currently-active discount for a product/variation row, or null. */
    public function activeDiscount(int $agentId, ?int $productId, ?int $variationId): ?ProductDiscount
    {
        $query = ProductDiscount::query()
            ->where('agent_id', $agentId)
            ->where('is_active', true);

        // Variation-targeted discount wins over product-targeted when both exist.
        // A1-09: filter by validity FIRST — a future/expired row must never mask an older
        // currently-valid row (it is excluded from the precedence entirely).
        if ($variationId) {
            $variantRows = (clone $query)->where('product_variation_id', $variationId)
                ->get()
                ->filter(fn (ProductDiscount $d) => $d->isCurrentlyValid());

            if ($variantRows->isNotEmpty()) {
                return $variantRows->sortByDesc('id')->first();
            }
        }

        if ($productId) {
            $productRows = (clone $query)
                ->where('product_id', $productId)
                ->whereNull('product_variation_id')
                ->get()
                ->filter(fn (ProductDiscount $d) => $d->isCurrentlyValid());

            if ($productRows->isNotEmpty()) {
                return $productRows->sortByDesc('id')->first();
            }
        }

        return null;
    }

    /**
     * Effective discounted unit price for a line, with metadata for display.
     *
     * @return array{unit_price: float, original_price: float, discount_percentage: int, discount_amount: float, has_discount: bool}
     */
    public function effectiveUnitPrice(int $agentId, ?int $productId, ?int $variationId, float $basePrice): array
    {
        $discount = $this->activeDiscount($agentId, $productId, $variationId);
        $original = max(0.0, round($basePrice, 2));
        $percentage = $discount ? max(0, min(100, (int) $discount->percentage)) : 0;

        $discountAmount = round($original * $percentage / 100, 2);
        // Never below zero.
        $effective = max(0.0, round($original - $discountAmount, 2));

        return [
            'unit_price' => $effective,
            'original_price' => $original,
            'discount_percentage' => $percentage,
            'discount_amount' => $discountAmount,
            'has_discount' => $discount !== null,
        ];
    }

    /**
     * Apply a voucher to a line subtotal, tracking the whole-cart voucher
     * budget. Returns [discount, remainingBudget].
     *
     * A1-07: a FIXED voucher has ONE transaction-level face-value budget —
     * `min(voucher.value, applicable subtotal)` capped at the FIRST line it
     * hits, then zero for every subsequent line. Splitting a cart into more
     * lines can never multiply the reduction. `$remainingBudget` therefore
     * starts at the voucher's face value (not the cart subtotal) and only
     * ever decreases by actual allocations; `remainingApplicableSubtotal` is
     * kept separate and just enforces the "never discount more than the
     * eligible subtotal" constraint line-by-line. Percentage semantics stay
     * independent (percentage of each eligible line, capped by the remaining
     * face-value budget so a line never over-discounts).
     *
     * @return array{0:float, 1:float} [discount, remainingBudget]
     */
    public function voucherLineDiscount(Voucher $voucher, float $lineSubtotal, float $remainingBudget, float $remainingApplicableSubtotal): array
    {
        $lineSubtotal = max(0.0, $lineSubtotal);

        if (! $voucher->isUsable() || $remainingBudget <= 0) {
            return [0.0, $remainingBudget];
        }

        if ($voucher->type === 'fixed') {
            $discount = min((float) $voucher->value, $lineSubtotal, $remainingBudget, $remainingApplicableSubtotal);
        } else {
            // percentage voucher
            $percentage = max(0.0, min(100.0, (float) $voucher->value));
            $discount = round($lineSubtotal * $percentage / 100, 2);
            $discount = min($discount, $remainingBudget, $remainingApplicableSubtotal);
        }

        $discount = max(0.0, round($discount, 2));

        return [$discount, max(0.0, round($remainingBudget - $discount, 2))];
    }
}