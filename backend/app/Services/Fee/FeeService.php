<?php

namespace App\Services\Fee;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductVariation;
use App\Models\ProductVariationFee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single place agent/sales/courier fee amounts are read or written.
 * Precedence mirrors the stock split exactly (Blueprint §Product/§Agent
 * Stock):
 *   - has_variations = true  -> the fee lives ONLY on the specific variation
 *     (product_variation_fees). The parent product never has its own fee row.
 *   - has_variations = false -> the fee lives on the product itself
 *     (product_fees).
 * There is no fallback chain between the two — a product is in exactly one
 * mode, so there is never a case of "variation fee missing, fall back to
 * product fee". A missing fee row simply means that beneficiary earns 0 for
 * that item, not an error.
 *
 * IMPORTANT: this service is read/write for the *current* configuration only.
 * It is never used to price a historical order — OrderService snapshots
 * whatever this resolves onto order_items at the moment of purchase, and
 * that snapshot is what every later read (dashboard, report, order detail)
 * uses. Changing a fee here has zero effect on past orders.
 */
class FeeService
{
    /** @return array{agent: float, sales: float, courier: float} */
    public function resolveForProduct(Product $product): array
    {
        if ($product->has_variations) {
            throw new ApiException(__('messages.fee.product_uses_variation'), 422);
        }

        return $this->keyedAmounts(
            ProductFee::query()->where('product_id', $product->id)->where('is_active', true)->get()
        );
    }

    /** @return array{agent: float, sales: float, courier: float} */
    public function resolveForVariation(ProductVariation $variation): array
    {
        return $this->keyedAmounts(
            ProductVariationFee::query()->where('product_variation_id', $variation->id)->where('is_active', true)->get()
        );
    }

    /** Convenience used by OrderService — resolves whichever mode $product is actually in. */
    public function resolveForLine(Product $product, ?ProductVariation $variation): array
    {
        return $variation ? $this->resolveForVariation($variation) : $this->resolveForProduct($product);
    }

    public function setForProduct(Product $product, float $agentFee, float $salesFee, float $courierFee): void
    {
        if ($product->has_variations) {
            throw new ApiException(
                __('messages.fee.variation_uses_product', ['name' => $product->name]),
                422
            );
        }

        DB::transaction(function () use ($product, $agentFee, $salesFee, $courierFee) {
            ProductFee::query()->updateOrCreate(
                ['product_id' => $product->id, 'beneficiary_role' => 'agent'],
                ['amount' => $agentFee, 'is_active' => true]
            );
            ProductFee::query()->updateOrCreate(
                ['product_id' => $product->id, 'beneficiary_role' => 'sales'],
                ['amount' => $salesFee, 'is_active' => true]
            );
            ProductFee::query()->updateOrCreate(
                ['product_id' => $product->id, 'beneficiary_role' => 'courier'],
                ['amount' => $courierFee, 'is_active' => true]
            );
        });
    }

    public function setForVariation(ProductVariation $variation, float $agentFee, float $salesFee, float $courierFee): void
    {
        DB::transaction(function () use ($variation, $agentFee, $salesFee, $courierFee) {
            ProductVariationFee::query()->updateOrCreate(
                ['product_variation_id' => $variation->id, 'beneficiary_role' => 'agent'],
                ['amount' => $agentFee, 'is_active' => true]
            );
            ProductVariationFee::query()->updateOrCreate(
                ['product_variation_id' => $variation->id, 'beneficiary_role' => 'sales'],
                ['amount' => $salesFee, 'is_active' => true]
            );
            ProductVariationFee::query()->updateOrCreate(
                ['product_variation_id' => $variation->id, 'beneficiary_role' => 'courier'],
                ['amount' => $courierFee, 'is_active' => true]
            );
        });
    }

    /** @return array{agent: float, sales: float, courier: float} */
    private function keyedAmounts(Collection $fees): array
    {
        $byRole = $fees->keyBy('beneficiary_role');

        return [
            'agent' => (float) ($byRole->get('agent')->amount ?? 0),
            'sales' => (float) ($byRole->get('sales')->amount ?? 0),
            'courier' => (float) ($byRole->get('courier')->amount ?? 0),
        ];
    }
}
