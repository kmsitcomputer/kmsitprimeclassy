<?php

namespace App\Services\Stock;

use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SellableStockService
{
    /**
     * Bulk canonical projection for operational reports and exports.
     *
     * One row is returned per agent/target. Any warehouse row makes warehouse
     * authoritative for that target; targets with no warehouse rows retain
     * the same legacy compatibility fallback as forProduct()/forVariation().
     *
     * @param  array<int, int>|null  $agentIds
     */
    public function projection(?array $agentIds = null): Builder
    {
        $simpleTargets = DB::table('product_stocks')
            ->select(['agent_id', 'product_id'])
            ->when($agentIds !== null, fn ($query) => $query->whereIn('agent_id', $agentIds))
            ->union(DB::table('warehouse_stocks')->whereNotNull('product_id')
                ->select(['agent_id', 'product_id'])
                ->when($agentIds !== null, fn ($query) => $query->whereIn('agent_id', $agentIds)));
        $variationTargets = DB::table('product_variation_stocks')
            ->select(['agent_id', 'product_variation_id'])
            ->when($agentIds !== null, fn ($query) => $query->whereIn('agent_id', $agentIds))
            ->union(DB::table('warehouse_stocks')->whereNotNull('product_variation_id')
                ->select(['agent_id', 'product_variation_id'])
                ->when($agentIds !== null, fn ($query) => $query->whereIn('agent_id', $agentIds)));

        $simpleWarehouse = $this->warehouseAggregate('product_id', $agentIds);
        $variationWarehouse = $this->warehouseAggregate('product_variation_id', $agentIds);

        $simple = DB::query()->fromSub($simpleTargets, 'targets')
            ->join('products as p', 'p.id', '=', 'targets.product_id')
            ->leftJoin('product_stocks as legacy', function ($join) {
                $join->on('legacy.agent_id', '=', 'targets.agent_id')->on('legacy.product_id', '=', 'targets.product_id');
            })
            ->leftJoinSub($simpleWarehouse, 'warehouse', function ($join) {
                $join->on('warehouse.agent_id', '=', 'targets.agent_id')->on('warehouse.target_id', '=', 'targets.product_id');
            })
            ->leftJoin('warehouse_settings as settings', 'settings.agent_id', '=', 'targets.agent_id')
            ->whereNull('p.deleted_at')
            ->selectRaw("COALESCE(legacy.id, warehouse.row_id) as id, targets.agent_id, 'product' as target_type, targets.product_id as target_id, p.sku, p.name as product_name")
            ->selectRaw($this->availableSql().' as quantity')
            ->selectRaw('COALESCE(legacy.quantity_reserved, 0) as reserved_quantity')
            ->selectRaw($this->sourceSql().' as source');

        $variations = DB::query()->fromSub($variationTargets, 'targets')
            ->join('product_variations as variation', 'variation.id', '=', 'targets.product_variation_id')
            ->join('products as p', 'p.id', '=', 'variation.product_id')
            ->leftJoin('product_variation_stocks as legacy', function ($join) {
                $join->on('legacy.agent_id', '=', 'targets.agent_id')->on('legacy.product_variation_id', '=', 'targets.product_variation_id');
            })
            ->leftJoinSub($variationWarehouse, 'warehouse', function ($join) {
                $join->on('warehouse.agent_id', '=', 'targets.agent_id')->on('warehouse.target_id', '=', 'targets.product_variation_id');
            })
            ->leftJoin('warehouse_settings as settings', 'settings.agent_id', '=', 'targets.agent_id')
            ->whereNull('variation.deleted_at')->whereNull('p.deleted_at')
            ->selectRaw("COALESCE(legacy.id, warehouse.row_id) as id, targets.agent_id, 'variation' as target_type, targets.product_variation_id as target_id, variation.sku, p.name as product_name")
            ->selectRaw($this->availableSql().' as quantity')
            ->selectRaw('COALESCE(legacy.quantity_reserved, 0) as reserved_quantity')
            ->selectRaw($this->sourceSql().' as source');

        return DB::query()->fromSub($simple->unionAll($variations), 'canonical_stock');
    }

    /**
     * Canonical sellable for a product (no variation).
     *
     * $reservedOverride lets a caller that already holds the stock row under
     * lockForUpdate pass the authoritative reserved quantity instead of
     * re-reading it, so delegation never adds a query on the checkout path.
     *
     * $lockWarehouse: true makes the Warehouse Transit/Plan read itself a locking read
     * (SELECT ... FOR UPDATE) instead of a plain snapshot read. A capacity DECISION (reserve,
     * or a Transit -> Sub transfer) must pass true — under REPEATABLE READ a plain SELECT can
     * still return a snapshot older than the row lock the caller just waited for, which is
     * exactly the race that let a Transit -> Sub transfer and an Agent checkout reservation
     * both observe enough capacity and both commit. A read-only display (product page, report)
     * must NOT lock and should leave this false.
     */
    public function forProduct(int $agentId, int $productId, ?int $reservedOverride = null, bool $lockWarehouse = false): array
    {
        $reserved = $reservedOverride ?? (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_reserved') ?? 0);

        return $this->calculate($agentId, $productId, null, $reserved, $lockWarehouse);
    }

    /** Canonical sellable for a product variation — see forProduct(). */
    public function forVariation(int $agentId, int $variationId, ?int $reservedOverride = null, bool $lockWarehouse = false): array
    {
        $reserved = $reservedOverride ?? (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $variationId)->value('quantity_reserved') ?? 0);

        return $this->calculate($agentId, null, $variationId, $reserved, $lockWarehouse);
    }

    /** @return array<string, array<string, mixed>> keyed by p:{id} or v:{id} */
    public function forTargets(int $agentId, Collection $productIds, Collection $variationIds): array
    {
        $warehouseRows = WarehouseStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->whereIn('stock_type', ['transit', 'factory_plan'])
            ->where(function ($query) use ($productIds, $variationIds) {
                $query->whereIn('product_id', $productIds->filter()->all())
                    ->whereNull('product_variation_id')
                    ->orWhereIn('product_variation_id', $variationIds->filter()->all())
                    ->whereNull('product_id');
            })->get()->groupBy(fn ($row) => $row->product_variation_id ? 'v:'.$row->product_variation_id : 'p:'.$row->product_id);
        $planEnabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
        $result = [];
        foreach ($productIds->filter()->unique() as $id) {
            $result['p:'.$id] = $this->targetFromRows($warehouseRows->get('p:'.$id, collect()), $agentId, (int) $id, null, $planEnabled);
        }
        foreach ($variationIds->filter()->unique() as $id) {
            $result['v:'.$id] = $this->targetFromRows($warehouseRows->get('v:'.$id, collect()), $agentId, null, (int) $id, $planEnabled);
        }

        return $result;
    }

    private function calculate(int $agentId, ?int $productId, ?int $variationId, int $reserved, bool $lockWarehouse = false): array
    {
        $query = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->whereIn('stock_type', ['transit', 'factory_plan']);
        $query->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'));
        $query->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'));
        $query->when($lockWarehouse, fn ($q) => $q->lockForUpdate());
        $rows = $query->get();
        $hasWarehouseRows = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))
            ->when($lockWarehouse, fn ($q) => $q->lockForUpdate())
            ->exists();
        $planEnabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
        $transit = (int) $rows->where('stock_type', 'transit')->sum('quantity');
        $plan = (int) $rows->where('stock_type', 'factory_plan')->sum('quantity');
        if (! $hasWarehouseRows) {
            $legacy = $productId
                ? (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_on_hand') ?? 0)
                : (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $variationId)->value('quantity_on_hand') ?? 0);

            return ['transit' => $legacy, 'factory_plan' => 0, 'factory_plan_enabled' => false, 'sellable_base' => $legacy, 'reserved' => $reserved, 'available' => max(0, $legacy - $reserved), 'physical_stock' => $legacy, 'has_commitment_deficit' => $reserved > $legacy, 'source' => 'legacy'];
        }
        $base = $transit + ($planEnabled ? $plan : 0);

        return ['transit' => $transit, 'factory_plan' => $plan, 'factory_plan_enabled' => $planEnabled, 'sellable_base' => $base, 'reserved' => $reserved, 'available' => max(0, $base - $reserved), 'physical_stock' => $transit, 'has_commitment_deficit' => $reserved > $base, 'source' => 'warehouse'];
    }

    private function targetFromRows(Collection $rows, int $agentId, ?int $productId, ?int $variationId, bool $planEnabled): array
    {
        $reserved = $variationId
            ? (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $variationId)->value('quantity_reserved') ?? 0)
            : (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_reserved') ?? 0);
        if ($rows->isEmpty()) {
            $legacy = $variationId ? (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $variationId)->value('quantity_on_hand') ?? 0) : (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_on_hand') ?? 0);

            return ['available' => max(0, $legacy - $reserved), 'source' => 'legacy'];
        }
        $base = (int) $rows->where('stock_type', 'transit')->sum('quantity') + ($planEnabled ? (int) $rows->where('stock_type', 'factory_plan')->sum('quantity') : 0);

        return ['available' => max(0, $base - $reserved), 'source' => 'warehouse'];
    }

    /** @param array<int, int>|null $agentIds */
    private function warehouseAggregate(string $targetColumn, ?array $agentIds): Builder
    {
        return DB::table('warehouse_stocks')
            ->whereNotNull($targetColumn)
            ->when($agentIds !== null, fn ($query) => $query->whereIn('agent_id', $agentIds))
            ->selectRaw("agent_id, {$targetColumn} as target_id, MIN(id) as row_id")
            ->selectRaw("SUM(CASE WHEN stock_type = 'transit' THEN quantity ELSE 0 END) as transit_quantity")
            ->selectRaw("SUM(CASE WHEN stock_type = 'factory_plan' THEN quantity ELSE 0 END) as plan_quantity")
            ->groupBy('agent_id', $targetColumn);
    }

    private function availableSql(): string
    {
        return 'GREATEST(0, (CASE WHEN warehouse.row_id IS NOT NULL '
            .'THEN COALESCE(warehouse.transit_quantity, 0) + CASE WHEN COALESCE(settings.factory_plan_enabled, 0) = 1 THEN COALESCE(warehouse.plan_quantity, 0) ELSE 0 END '
            .'ELSE COALESCE(legacy.quantity_on_hand, 0) END) - COALESCE(legacy.quantity_reserved, 0))';
    }

    private function sourceSql(): string
    {
        return "CASE WHEN warehouse.row_id IS NOT NULL THEN 'warehouse' ELSE 'legacy' END";
    }
}
