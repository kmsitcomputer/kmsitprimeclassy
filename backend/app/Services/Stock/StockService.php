<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change agent_stocks/product_variation_stocks
 * quantities. Every write here happens under a row lock (see reserve*) so two
 * concurrent orders against the same low-stock item cannot both succeed
 * (Blueprint §Agent Stock "Race condition").
 *
 * Callers must already be inside a DB::transaction() — this service does not
 * open its own, so it composes correctly inside OrderService's transaction.
 */
class StockService
{
    /**
     * Reserve $qty units of a product (no variation) for $agentId, or throw
     * if not enough is available. Uses withoutGlobalScopes() deliberately:
     * this is an internal, parameter-driven operation against an agentId
     * that was already resolved server-side from the konsumen's own referral
     * chain — it must not be re-filtered by the *acting* user's own scope.
     */
    public function reserveForProduct(
        int $agentId,
        Product $product,
        int $qty,
        string $referenceType,
        int $referenceId,
        ?int $createdBy
    ): void {
        $stock = ProductStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $available = $stock?->availableQuantity() ?? 0;

        if (! $stock || $available < $qty) {
            throw new InsufficientStockException($product->name, $available, $qty);
        }

        $stock->increment('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId,
            'product_id' => $product->id,
            'type' => 'reserve',
            'quantity' => $qty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
        ]);
    }

    public function reserveForVariation(
        int $agentId,
        ProductVariation $variation,
        int $qty,
        string $referenceType,
        int $referenceId,
        ?int $createdBy
    ): void {
        $stock = ProductVariationStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->where('product_variation_id', $variation->id)
            ->lockForUpdate()
            ->first();

        $available = $stock?->availableQuantity() ?? 0;

        if (! $stock || $available < $qty) {
            throw new InsufficientStockException($variation->sku, $available, $qty);
        }

        $stock->increment('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId,
            'product_variation_id' => $variation->id,
            'type' => 'reserve',
            'quantity' => $qty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
        ]);
    }

    /** Reservation → committed deduction (e.g. on payment confirmation). */
    public function deductProduct(int $agentId, int $productId, int $qty, string $referenceType, int $referenceId, ?int $createdBy): void
    {
        $stock = ProductStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_id', $productId)
            ->lockForUpdate()->firstOrFail();

        $this->assertSufficient($stock->quantity_on_hand, $qty, 'quantity_on_hand');
        $this->assertSufficient($stock->quantity_reserved, $qty, 'quantity_reserved');

        $stock->decrement('quantity_on_hand', $qty);
        $stock->decrement('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId, 'product_id' => $productId, 'type' => 'out',
            'quantity' => -$qty, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'created_by' => $createdBy,
        ]);
    }

    /** Reservation released without deduction (e.g. order cancelled before payment). */
    public function releaseProduct(int $agentId, int $productId, int $qty, string $referenceType, int $referenceId, ?int $createdBy): void
    {
        $stock = ProductStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_id', $productId)
            ->lockForUpdate()->firstOrFail();

        $this->assertSufficient($stock->quantity_reserved, $qty, 'quantity_reserved');

        $stock->decrement('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId, 'product_id' => $productId, 'type' => 'release',
            'quantity' => -$qty, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'created_by' => $createdBy,
        ]);
    }

    public function deductVariation(int $agentId, int $variationId, int $qty, string $referenceType, int $referenceId, ?int $createdBy): void
    {
        $stock = ProductVariationStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_variation_id', $variationId)
            ->lockForUpdate()->firstOrFail();

        $this->assertSufficient($stock->quantity_on_hand, $qty, 'quantity_on_hand');
        $this->assertSufficient($stock->quantity_reserved, $qty, 'quantity_reserved');

        $stock->decrement('quantity_on_hand', $qty);
        $stock->decrement('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId, 'product_variation_id' => $variationId, 'type' => 'out',
            'quantity' => -$qty, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'created_by' => $createdBy,
        ]);
    }

    public function releaseVariation(int $agentId, int $variationId, int $qty, string $referenceType, int $referenceId, ?int $createdBy): void
    {
        $stock = ProductVariationStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_variation_id', $variationId)
            ->lockForUpdate()->firstOrFail();

        $this->assertSufficient($stock->quantity_reserved, $qty, 'quantity_reserved');

        $stock->decrement('quantity_reserved', $qty);

        StockMovement::create([
            'agent_id' => $agentId, 'product_variation_id' => $variationId, 'type' => 'release',
            'quantity' => -$qty, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'created_by' => $createdBy,
        ]);
    }

    /**
     * Manual stock correction by an agent/admin/super_admin (restock, stock
     * opname, initial setup) — distinct from the order-driven reserve/deduct/
     * release above. Creates the underlying stock row on first use (an agent
     * starts every product at zero until explicitly stocked). $delta may be
     * negative (e.g. correcting for spoilage) but the resulting
     * quantity_on_hand can never go below zero — Blueprint rule "stock tidak
     * boleh negatif".
     */
    public function adjustProduct(int $agentId, int $productId, int $delta, string $reason, ?int $actorId): ProductStock
    {
        return DB::transaction(function () use ($agentId, $productId, $delta, $reason, $actorId) {
            $stock = ProductStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)->where('product_id', $productId)
                ->lockForUpdate()->first();

            if (! $stock) {
                $stock = ProductStock::create([
                    'agent_id' => $agentId, 'product_id' => $productId,
                    'quantity_on_hand' => 0, 'quantity_reserved' => 0,
                ]);
            }

            $resulting = $stock->quantity_on_hand + $delta;

            if ($resulting < 0) {
                throw new ApiException(
                    __('messages.stock.negative_result'), 422,
                    ['quantity_on_hand' => $stock->quantity_on_hand, 'delta' => $delta]
                );
            }

            $stock->update(['quantity_on_hand' => $resulting]);

            StockMovement::create([
                'agent_id' => $agentId, 'product_id' => $productId, 'type' => 'adjustment',
                'quantity' => $delta, 'note' => $reason, 'created_by' => $actorId,
            ]);

            return $stock->fresh();
        });
    }

    public function adjustVariation(int $agentId, int $variationId, int $delta, string $reason, ?int $actorId): ProductVariationStock
    {
        return DB::transaction(function () use ($agentId, $variationId, $delta, $reason, $actorId) {
            $stock = ProductVariationStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)->where('product_variation_id', $variationId)
                ->lockForUpdate()->first();

            if (! $stock) {
                $stock = ProductVariationStock::create([
                    'agent_id' => $agentId, 'product_variation_id' => $variationId,
                    'quantity_on_hand' => 0, 'quantity_reserved' => 0,
                ]);
            }

            $resulting = $stock->quantity_on_hand + $delta;

            if ($resulting < 0) {
                throw new ApiException(
                    __('messages.stock.negative_result'), 422,
                    ['quantity_on_hand' => $stock->quantity_on_hand, 'delta' => $delta]
                );
            }

            $stock->update(['quantity_on_hand' => $resulting]);

            StockMovement::create([
                'agent_id' => $agentId, 'product_variation_id' => $variationId, 'type' => 'adjustment',
                'quantity' => $delta, 'note' => $reason, 'created_by' => $actorId,
            ]);

            return $stock->fresh();
        });
    }

    private function assertSufficient(int $available, int $needed, string $column): void
    {
        if ($available < $needed) {
            throw new ApiException(
                __('messages.stock.insufficient_column', ['column' => $column]), 422,
                ['available' => $available, 'needed' => $needed]
            );
        }
    }
}
