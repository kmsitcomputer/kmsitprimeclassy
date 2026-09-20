<?php

namespace App\Console\Commands;

use App\Models\InventoryCancellationReversal;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\ReturnItem;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileWarehouseStock extends Command
{
    protected $signature = 'warehouse:reconcile {--agent_id=}';

    protected $description = 'Reconcile Phase C warehouse buckets against reservations and legacy projections.';

    public function handle(): int
    {
        $requiredTables = ['warehouse_stocks', 'product_stocks', 'product_variation_stocks'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table) => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            $this->error('BLOCKED: required warehouse schema is not migrated: '.implode(', ', $missingTables));

            return self::FAILURE;
        }

        $query = WarehouseStock::withoutGlobalScopes()->orderBy('agent_id');
        if ($this->option('agent_id')) {
            $query->where('agent_id', (int) $this->option('agent_id'));
        }

        $rows = $query->get();
        $negative = $rows->where('quantity', '<', 0);
        $legacyAgentIds = ProductStock::withoutGlobalScopes()->pluck('agent_id')
            ->merge(ProductVariationStock::withoutGlobalScopes()->pluck('agent_id'));
        $agentIds = $rows->pluck('agent_id')->merge($legacyAgentIds)->unique();
        foreach ($agentIds as $agentId) {
            $agentRows = $rows->where('agent_id', $agentId);
            $transit = (int) $agentRows->where('stock_type', 'transit')->sum('quantity');
            $plan = (int) $agentRows->where('stock_type', 'factory_plan')->sum('quantity');
            $shipping = (int) $agentRows->where('stock_type', 'shipping')->sum('quantity');
            $sub = (int) $agentRows->where('stock_type', 'sub')->sum('quantity');
            $enabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
            $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_reserved')
                + (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_reserved');
            $legacy = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_on_hand')
                + (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_on_hand');
            $sellable = $transit + ($enabled ? $plan : 0) - $reserved;
            $subByLocation = $agentRows->where('stock_type', 'sub')->groupBy('sub_location_id')->map(fn ($locationRows, $locationId) => $locationId.':'.$locationRows->sum('quantity'))->implode(',');
            $this->line("agent={$agentId} PHYSICAL transit={$transit} sub_total={$sub} sub_by_location={$subByLocation} shipping={$shipping} physical_total=".($transit + $sub + $shipping)." PLANNED factory_plan={$plan} enabled=".($enabled ? '1' : '0')." DERIVED reserved={$reserved} sellable_available=".max(0, $sellable).' commitment_deficit='.($sellable < 0 ? '1' : '0')." LEGACY on_hand={$legacy}");
        }
        $this->info("warehouse_rows={$rows->count()} negative_rows={$negative->count()}");

        $completedTransfers = StockTransfer::withoutGlobalScopes()->where('status', 'completed')->get();
        $movementPairIssues = 0;
        $orphanMovementIssues = 0;
        $statusIssues = 0;
        $orphanHandoverIssues = 0;
        foreach ($completedTransfers as $transfer) {
            $movements = StockMovement::query()->where('transfer_id', $transfer->id)->get();
            $out = (int) $movements->where('type', 'transfer_out')->sum('quantity');
            $in = (int) $movements->where('type', 'transfer_in')->sum('quantity');
            if ($movements->count() === 0 || $out + $in !== 0) {
                $movementPairIssues++;
            }
            if (! StockHandover::withoutGlobalScopes()->where('stock_transfer_id', $transfer->id)->exists()) {
                $orphanHandoverIssues++;
            }
        }
        foreach (StockMovement::query()->whereIn('type', ['transfer_in', 'transfer_out'])->whereNotNull('transfer_id')->get() as $movement) {
            if (! StockTransfer::withoutGlobalScopes()->whereKey($movement->transfer_id)->exists()) {
                $orphanMovementIssues++;
            }
            if ($movement->stock_type === 'sub' && (! $movement->sub_location_id || ! WarehouseSubLocation::withoutGlobalScopes()->whereKey($movement->sub_location_id)->where('agent_id', $movement->agent_id)->exists())) {
                $orphanMovementIssues++;
            }
        }
        $statusIssues = StockTransfer::withoutGlobalScopes()->where('status', 'completed')->whereDoesntHave('handover')->count();
        $this->info("transfer_pair_issues={$movementPairIssues} orphan_transfer_movements={$orphanMovementIssues} transfer_status_issues={$statusIssues} orphan_handovers={$orphanHandoverIssues}");

        $opnameMovementIssues = StockMovement::query()->whereNotNull('opname_id')->whereDoesntHave('opname')->count();
        $approvedWithoutMovement = 0;
        $duplicateOpnameApplications = 0;
        foreach (StockOpname::withoutGlobalScopes()->with('items')->where('status', 'approved')->get() as $opname) {
            $required = $opname->items->where('difference', '!=', 0)->count();
            $applied = StockMovement::query()->where('opname_id', $opname->id)->count();
            if ($required > 0 && $applied === 0) {
                $approvedWithoutMovement++;
            }
            if ($applied > $required) {
                $duplicateOpnameApplications++;
            }
        }
        $staleSubmitted = StockOpname::withoutGlobalScopes()->where('status', 'submitted')->where('created_at', '<', now()->subDay())->count();
        $invalidPhysicalTargets = StockOpname::withoutGlobalScopes()->where('opname_type', 'physical_opname')->whereNotIn('stock_type', ['transit', 'shipping', 'sub'])->count();
        $salesRows = WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sales')->count();
        $commitmentDeficits = 0;
        foreach ($agentIds as $agentId) {
            $transit = (int) $rows->where('agent_id', $agentId)->where('stock_type', 'transit')->sum('quantity');
            $plan = (int) $rows->where('agent_id', $agentId)->where('stock_type', 'factory_plan')->sum('quantity');
            $enabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
            $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_reserved') + (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->sum('quantity_reserved');
            if ($reserved > $transit + ($enabled ? $plan : 0)) {
                $commitmentDeficits++;
            }
        }
        $this->info("opname_orphan_movements={$opnameMovementIssues} approved_without_movement={$approvedWithoutMovement} duplicate_opname_applications={$duplicateOpnameApplications} stale_submitted={$staleSubmitted} invalid_physical_targets={$invalidPhysicalTargets} sales_rows={$salesRows} commitment_deficits={$commitmentDeficits}");

        $processingOrdersMissingRequest = Order::withoutGlobalScopes()->where('status', 'diproses')->whereDoesntHave('stockRequest')->count();
        $duplicateRequests = (int) DB::table('stock_requests')->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $requestItemMismatches = 0;
        $fulfillmentMovementIssues = 0;
        foreach (StockRequest::withoutGlobalScopes()->with(['order.items', 'items'])->get() as $request) {
            if ($request->order && $request->items->count() !== $request->order->items->count()) {
                $requestItemMismatches++;
            }
            foreach ($request->items as $item) {
                if ($item->fulfilled_qty > $item->requested_qty || $item->remaining_qty !== $item->requested_qty - $item->fulfilled_qty) {
                    $requestItemMismatches++;
                }
                if ($item->fulfilled_qty > 0 && ! StockMovement::query()->where('reference_type', StockRequest::class)->where('reference_id', $request->id)->where('product_id', $item->product_id)->where('type', 'fulfillment')->exists()) {
                    $fulfillmentMovementIssues++;
                }
            }
        }
        $this->info("processing_orders_missing_request={$processingOrdersMissingRequest} duplicate_stock_requests={$duplicateRequests} stock_request_item_mismatches={$requestItemMismatches} fulfillment_movement_issues={$fulfillmentMovementIssues}");

        $duplicateCancellationReversals = (int) DB::table('inventory_cancellation_reversals')->select('order_item_id')->groupBy('order_item_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $orphanCancellationMovements = StockMovement::query()->where('type', 'cancellation_release')->whereNotNull('reference_id')->where('reference_type', Order::class)->whereNotExists(fn ($q) => $q->from('inventory_cancellation_reversals as r')->whereColumn('r.order_id', 'stock_movements.reference_id'))->count();
        $unreleasedCancelledReservations = 0;
        foreach (InventoryCancellationReversal::query()->get() as $reversal) {
            if ($reversal->released_quantity > 0 && ! StockMovement::query()->where('reference_type', 'order')->where('reference_id', $reversal->order_id)->where('type', 'release')->exists()) {
                $unreleasedCancelledReservations++;
            }
        }
        $uninspectedApprovedReturns = ReturnItem::query()->where('status', 'approved')->whereNull('inspected_at')->count();
        $damagedInTransit = ReturnItem::query()->where('damaged_quantity', '>', 0)->where('condition_status', '!=', 'pending')->where('restock_processed_at', '!=', null)->whereHas('orderItem', fn ($q) => $q->whereHas('product'))->count();
        $this->info("duplicate_cancellation_reversals={$duplicateCancellationReversals} orphan_cancellation_movements={$orphanCancellationMovements} unreleased_cancelled_reservations={$unreleasedCancelledReservations} approved_returns_uninspected={$uninspectedApprovedReturns} damaged_return_audit_rows={$damagedInTransit}");

        return $negative->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
