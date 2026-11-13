<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockOpnameService
{
    public function create(User $actor, string $type, ?string $stockType, ?int $subLocationId, array $items, ?string $notes = null): StockOpname
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        $this->assertTypeTarget($type, $stockType, $subLocationId, $actor->agent_id);
        if ($type === 'sellable_reconciliation') {
            throw new ApiException('Sellable reconciliation bersifat read-only.', 422);
        }

        return DB::transaction(function () use ($actor, $type, $stockType, $subLocationId, $items, $notes) {
            $opname = StockOpname::create(['agent_id' => $actor->agent_id, 'opname_number' => $this->uniqueNumber(), 'opname_type' => $type, 'stock_type' => $stockType, 'sub_location_id' => $subLocationId, 'notes' => $notes, 'created_by' => $actor->id]);
            $seen = [];
            foreach ($items as $input) {
                $productId = isset($input['product_id']) ? (int) $input['product_id'] : null;
                $variationId = isset($input['product_variation_id']) ? (int) $input['product_variation_id'] : null;
                $this->assertItem($productId, $variationId, $subLocationId, $actor->agent_id);
                $key = $variationId ? 'v:'.$variationId : 'p:'.$productId;
                if (isset($seen[$key])) {
                    throw new ApiException('Target opname tidak boleh duplikat.', 422);
                }
                $seen[$key] = true;
            }
            // Use the existing target order; snapshot locks must agree with multi-target writers.
            foreach (StockService::canonicalReservationTargets($items) as $target) {
                $productId = $target['product_id'];
                $variationId = $target['product_variation_id'];
                $snapshot = $this->snapshot($actor->agent_id, $stockType, $subLocationId, $productId, $variationId);
                $opname->items()->create(['product_id' => $productId, 'product_variation_id' => $variationId, 'sub_location_id' => $subLocationId] + $snapshot);
            }

            return $opname->load('items');
        });
    }

    public function count(User $actor, StockOpname $opname, array $counts): StockOpname
    {
        $this->assertOwn($actor, $opname);
        if (! $actor->isRole('gudang')) {
            throw new ApiException('Opname tidak dapat diedit.', 422);
        }

        return DB::transaction(function () use ($actor, $opname, $counts) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ApiException('Opname tidak dapat diedit.', 422);
            }
            foreach ($counts as $input) {
                $item = $locked->items()->findOrFail($input['item_id']);
                $counted = (int) $input['counted_quantity'];
                if ($counted < 0) {
                    throw new ApiException('Jumlah hitung tidak boleh negatif.', 422);
                }
                $item->update(['counted_quantity' => $counted, 'difference' => $counted - $item->system_quantity]);
            }

            return $locked->fresh()->load('items');
        });
    }

    public function submit(User $actor, StockOpname $opname): StockOpname
    {
        $this->assertOwn($actor, $opname);
        if (! $actor->isRole('gudang')) {
            throw new ApiException('Opname belum siap disubmit.', 422);
        }

        return DB::transaction(function () use ($actor, $opname) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || $locked->items()->whereNull('counted_quantity')->exists()) {
                throw new ApiException('Opname belum siap disubmit.', 422);
            }
            $locked->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);

            return $locked->fresh()->load('items');
        });
    }

    public function approve(User $actor, StockOpname $opname): StockOpname
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui opname.', 403);
        }

        return DB::transaction(function () use ($actor, $opname) {
            $lockedOpname = StockOpname::withoutGlobalScopes()->with('items')->whereKey($opname->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($lockedOpname->status === 'approved') {
                return $lockedOpname;
            }
            if ($lockedOpname->status !== 'submitted') {
                throw new ApiException('Hanya opname submitted yang dapat disetujui.', 422);
            }
            $physical = $lockedOpname->opname_type === 'physical_opname';
            $plan = $lockedOpname->opname_type === 'plan_reconciliation';
            if (! $physical && ! $plan) {
                throw new ApiException('Tipe opname ini tidak dapat disetujui.', 422);
            }
            if ($lockedOpname->stock_type === 'sub') {
                WarehouseSubLocation::query()->whereKey($lockedOpname->sub_location_id)->lockForUpdate()->firstOrFail();
            }
            foreach ($lockedOpname->items->sortBy(fn ($i) => StockService::canonicalTargetKey($i->product_id, $i->product_variation_id)) as $item) {
                $stock = $this->lockedStock($lockedOpname->agent_id, $item);
                $current = $stock?->quantity ?? 0;
                if ($current !== $item->system_quantity
                    || (($item->import_row_key !== null || $item->system_stock_revision !== null) && ($item->system_stock_revision === null
                        || $stock?->id !== $item->system_stock_id || app(PhysicalStockRevision::class)->current($lockedOpname->agent_id, $lockedOpname->stock_type, $item->sub_location_id, $item->product_id, $item->product_variation_id) !== $item->system_stock_revision))) {
                    throw new ApiException('Stock changed after this opname was counted. Recount is required.', 409);
                }
                $delta = $item->counted_quantity - $current;
                if ($delta === 0) {
                    continue;
                }
                if ($physical && $lockedOpname->stock_type === 'sub' && $delta < 0) {
                    // R-02: a Sub opname may never cut physical below active Sub reservations.
                    app(SubStockService::class)->assertPhysicalDecreaseAllowed($item->sub_location_id, $item->product_variation_id ? null : $item->product_id, $item->product_variation_id, $current, -$delta);
                }
                if ($plan) {
                    $enabled = WarehouseSetting::query()->where('agent_id', $lockedOpname->agent_id)->value('factory_plan_enabled') ?? false;
                    if (! $enabled || $current + $delta < 0) {
                        throw new ApiException('Plan reconciliation tidak valid.', 422);
                    }
                    $reserved = $this->reserved($lockedOpname->agent_id, $item);
                    $transit = $this->currentQuantity($lockedOpname->agent_id, $item->product_id, $item->product_variation_id, 'transit', null);
                    if ($transit + $current + $delta < $reserved) {
                        throw new ApiException('Plan reconciliation melampaui komitmen reservasi.', 422);
                    }
                }
                $stock ??= WarehouseStock::create(['agent_id' => $lockedOpname->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'stock_type' => $lockedOpname->stock_type, 'sub_location_id' => $item->sub_location_id, 'quantity' => 0]);
                $stock->update(['quantity' => $item->counted_quantity]);
                StockMovement::create(['agent_id' => $lockedOpname->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'type' => $physical ? 'opname_adjustment' : ($delta > 0 ? 'factory_plan_in' : 'factory_plan_out'), 'quantity' => $delta, 'stock_type' => $lockedOpname->stock_type, 'sub_location_id' => $item->sub_location_id, 'reference_type' => StockOpname::class, 'reference_id' => $lockedOpname->id, 'opname_id' => $lockedOpname->id, 'created_by' => $actor->id]);
                $item->update(['difference' => $delta, 'adjusted_quantity' => $item->counted_quantity]);
            }
            // REM-01 / D-07: a withheld catalogue change is published by THIS commit, never earlier,
            // and never if anything above threw (stale 409, Sub floor …) — the transaction rolls both back.
            foreach ($lockedOpname->items as $item) {
                if ($item->pending_catalog_change !== null && $item->catalog_published_at === null) {
                    $change = $item->pending_catalog_change;
                    app(\App\Services\Catalog\CatalogImportService::class)
                        ->applyChange($change['kind'], (int) $change['id'], $change['changes'] ?? [], $change['attributes'] ?? null);
                    $item->update(['catalog_published_at' => now()]);
                }
            }
            $lockedOpname->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $lockedOpname->fresh()->load('items');
        });
    }

    public function findImportRow(string $rowKey): ?StockOpnameItem
    {
        return StockOpnameItem::query()->where('import_row_key', $rowKey)->first();
    }

    /**
     * REM-01: one single-target physical opname (draft) per import row so approval is independent
     * per row. The file's number is only the PROPOSED target; the Gudang still counts and submits and
     * the Admin still approves through the unchanged flow. The row key is a UNIQUE column: a concurrent
     * duplicate loses at the database, not at an application check.
     *
     * @param  ?array<string,mixed>  $pendingCatalogChange
     */
    public function stageImportRow(User $importer, int $agentId, string $stockType, ?int $subLocationId, ?int $productId, ?int $variationId, string $rowKey, string $fingerprint, int $target, ?array $pendingCatalogChange): StockOpnameItem
    {
        $this->assertTypeTarget('physical_opname', $stockType, $subLocationId, $agentId);
        $this->assertItem($productId, $variationId, $subLocationId, $agentId);

        try {
            $opname = StockOpname::create(['agent_id' => $agentId, 'opname_number' => $this->uniqueNumber(), 'opname_type' => 'physical_opname', 'stock_type' => $stockType, 'sub_location_id' => $subLocationId, 'notes' => 'Import katalog (target fisik diusulkan, wajib dihitung Gudang)', 'created_by' => $importer->id]);

            $snapshot = $this->snapshot($agentId, $stockType, $subLocationId, $productId, $variationId);

            return $opname->items()->create([
                'product_id' => $productId, 'product_variation_id' => $variationId, 'sub_location_id' => $subLocationId,
                'import_row_key' => $rowKey, 'import_fingerprint' => $fingerprint, 'import_target_quantity' => $target,
                'pending_catalog_change' => $pendingCatalogChange,
            ] + $snapshot);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException('Baris import yang sama sedang diproses.', 409);
            }
            throw $e;
        }
    }

    public function reject(User $actor, StockOpname $opname, string $reason): StockOpname
    {
        $this->assertOwn($actor, $opname);
        if (! $actor->isRole('admin')) {
            throw new ApiException('Opname tidak dapat ditolak.', 422);
        }

        return DB::transaction(function () use ($actor, $opname, $reason) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted') {
                throw new ApiException('Opname tidak dapat ditolak.', 422);
            }
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $locked->fresh();
        });
    }

    public function cancel(User $actor, StockOpname $opname): StockOpname
    {
        $this->assertOwn($actor, $opname);
        if (! $actor->isRole('gudang')) {
            throw new ApiException('Opname tidak dapat dibatalkan.', 422);
        }

        return DB::transaction(function () use ($actor, $opname) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ApiException('Opname tidak dapat dibatalkan.', 422);
            }
            $locked->update(['status' => 'cancelled']);

            return $locked->fresh();
        });
    }

    /** Quantity and token captured under the same canonical target lock. */
    private function snapshot(int $agentId, string $type, ?int $locationId, ?int $productId, ?int $variationId): array
    {
        if ($locationId !== null) {
            WarehouseSubLocation::query()->whereKey($locationId)->lockForUpdate()->firstOrFail();
        }
        // Absence must stay absent: a zero placeholder would break legacy sellable fallback.
        $stock = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->where('stock_type', $type)->where('sub_location_id', $locationId)
            ->where('product_id', $productId)->where('product_variation_id', $variationId)->lockForUpdate()->first();

        return ['system_quantity' => $stock?->quantity ?? 0, 'system_stock_id' => $stock?->id,
            'system_stock_revision' => app(PhysicalStockRevision::class)->current($agentId, $type, $locationId, $productId, $variationId)];
    }

    private function currentQuantity(int $agentId, ?int $productId, ?int $variationId, ?string $stockType, ?int $subLocationId): int
    {
        return (int) (WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $stockType)->where('sub_location_id', $subLocationId)->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))->value('quantity') ?? 0);
    }

    private function lockedStock(int $agentId, StockOpnameItem $item): ?WarehouseStock
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $item->opname->stock_type)->where('sub_location_id', $item->sub_location_id)->when($item->product_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))->lockForUpdate()->first();
    }

    private function reserved(int $agentId, StockOpnameItem $item): int
    {
        return (int) ($item->product_variation_id ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $item->product_variation_id)->value('quantity_reserved') : ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $item->product_id)->value('quantity_reserved') ?? 0);
    }

    private function assertOwn(User $actor, StockOpname $opname): void
    {
        if (! $actor->agent_id || $opname->agent_id !== $actor->agent_id) {
            throw new ApiException('Opname bukan milik network ini.', 404);
        }
    }

    private function assertTypeTarget(string $type, ?string $stockType, ?int $subLocationId, int $agentId): void
    {
        if (! in_array($type, ['physical_opname', 'plan_reconciliation'], true) || ($type === 'physical_opname' && ! in_array($stockType, ['transit', 'shipping', 'sub'], true)) || ($type === 'plan_reconciliation' && $stockType !== 'factory_plan') || ($stockType === 'sub') !== ($subLocationId !== null)) {
            throw new ApiException('Target reconciliation tidak valid.', 422);
        }
    }

    private function assertItem(?int $productId, ?int $variationId, ?int $subLocationId, int $agentId): void
    {
        // Domain-boundary guard: the Sub Location must be an ACTIVE location of THIS Agent (never trust the caller).
        if (($productId === null) === ($variationId === null) || ($subLocationId !== null && ! WarehouseSubLocation::query()->whereKey($subLocationId)->where('agent_id', $agentId)->where('is_active', true)->exists())) {
            throw new ApiException('Target item opname tidak valid.', 422);
        }
    }

    private function uniqueNumber(): string
    {
        do {
            $number = 'OPN-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));
        } while (DB::table('stock_opnames')->where('opname_number', $number)->exists());

        return $number;
    }
}
