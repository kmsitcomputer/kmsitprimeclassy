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

        $available = $this->availableProduct($agentId, $product->id, $stock);

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

        $available = $this->availableVariation($agentId, $variation->id, $stock);

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
     * Canonical key for one inventory target: `p:{product_id}` for a bare product, `v:{variation_id}`
     * for a variation. It is the SINGLE ordering vocabulary every multi-target inventory lock in a
     * transaction must sort by — a multi-line Agent checkout and a multi-target Transit -> Sub
     * execution must acquire the same target order, or two concurrent transactions that list the
     * same targets in opposite orders can each hold one and wait on the other (deadlock).
     */
    public static function canonicalTargetKey(?int $productId, ?int $variationId): string
    {
        return $variationId !== null ? 'v:'.$variationId : 'p:'.$productId;
    }

    /**
     * Sorts reservation targets into the canonical target order (see canonicalTargetKey) and drops
     * duplicate targets. Deterministic across every caller that must lock more than one target.
     *
     * @param  iterable<array{product_id:?int, product_variation_id:?int}>  $targets
     * @return list<array{product_id:?int, product_variation_id:?int}>
     */
    public static function canonicalReservationTargets(iterable $targets): array
    {
        $ordered = [];
        foreach ($targets as $target) {
            $productId = isset($target['product_id']) ? (int) $target['product_id'] : null;
            $variationId = ! empty($target['product_variation_id']) ? (int) $target['product_variation_id'] : null;
            $ordered[self::canonicalTargetKey($productId, $variationId)] = [
                'product_id' => $variationId !== null ? null : $productId,
                'product_variation_id' => $variationId,
            ];
        }
        ksort($ordered);

        return array_values($ordered);
    }

    /**
     * Locks one Agent inventory target exactly as reserveForProduct()/reserveForVariation() will:
     * the Agent commitment row (ProductStock/ProductVariationStock) FIRST, then the Warehouse
     * Transit/Plan row(s) it competes with. Callers reserving more than one target must take them
     * in canonicalReservationTargets() order (see lockReservationTargets()).
     */
    public function lockReservationTarget(int $agentId, ?int $productId, ?int $variationId): void
    {
        $sellable = app(SellableStockService::class);

        if ($variationId !== null) {
            ProductVariationStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)->where('product_variation_id', $variationId)
                ->lockForUpdate()->first();
            $sellable->forVariation($agentId, $variationId, null, lockWarehouse: true);

            return;
        }

        ProductStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_id', $productId)
            ->lockForUpdate()->first();
        $sellable->forProduct($agentId, $productId, null, lockWarehouse: true);
    }

    /**
     * Acquires every Agent capacity lock a reservation pass will need, in ONE canonical
     * deterministic target order, BEFORE any per-line reserve call runs. This is what serialises a
     * multi-line checkout against a multi-target Transit -> Sub execution (and against another
     * checkout) that lists the same targets in a different order. Presentation/creation order is
     * untouched — the caller still processes its own lines in the original order afterwards; the
     * per-line reserve re-locks rows this transaction already holds without acquiring anything new.
     *
     * @param  iterable<array{product_id:?int, product_variation_id:?int}>  $targets
     */
    public function lockReservationTargets(int $agentId, iterable $targets): void
    {
        foreach (self::canonicalReservationTargets($targets) as $target) {
            $this->lockReservationTarget($agentId, $target['product_id'], $target['product_variation_id']);
        }
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
        $this->assertLegacyAdjustmentAllowed();

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
        $this->assertLegacyAdjustmentAllowed();

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

    /**
     * L-006: the sellable formula lives in exactly one place. Checkout asks the
     * canonical SellableStockService instead of keeping a second copy that can
     * drift (e.g. it used to fall back to legacy on-hand when only excluded
     * buckets such as `shipping` held stock, which allowed overselling).
     *
     * $stock is the row already held under lockForUpdate by reserve*(), so its
     * reserved value is authoritative and no extra query is needed.
     */
    private function availableProduct(int $agentId, int $productId, ?ProductStock $stock): int
    {
        // lockWarehouse: true — this feeds a reserve decision, so it must serialize against a
        // concurrent Transit -> Sub transfer through the same canonical lock order (see
        // StockTransferService::lockAgentCapacityForTransitToSub's docblock).
        return app(SellableStockService::class)
            ->forProduct($agentId, $productId, $stock?->quantity_reserved === null ? null : (int) $stock->quantity_reserved, lockWarehouse: true)['available'];
    }

    private function availableVariation(int $agentId, int $variationId, ?ProductVariationStock $stock): int
    {
        return app(SellableStockService::class)
            ->forVariation($agentId, $variationId, $stock?->quantity_reserved === null ? null : (int) $stock->quantity_reserved, lockWarehouse: true)['available'];
    }

    private function assertLegacyAdjustmentAllowed(): void
    {
        if (config('warehouse.authoritative')) {
            throw new ApiException('Legacy stock adjustment is disabled in warehouse-authoritative mode.', 403);
        }
    }
}
