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
                $this->assertItem($productId, $variationId, $subLocationId);
                $key = $variationId ? 'v:'.$variationId : 'p:'.$productId;
                if (isset($seen[$key])) {
                    throw new ApiException('Target opname tidak boleh duplikat.', 422);
                }
                $seen[$key] = true;
                $system = $this->currentQuantity($actor->agent_id, $productId, $variationId, $stockType, $subLocationId);
                $opname->items()->create(['product_id' => $productId, 'product_variation_id' => $variationId, 'sub_location_id' => $subLocationId, 'system_quantity' => $system]);
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
            foreach ($lockedOpname->items as $item) {
                $stock = $this->lockedStock($lockedOpname->agent_id, $item);
                $current = $stock?->quantity ?? 0;
                if ($current !== $item->system_quantity) {
                    throw new ApiException('Stock changed after this opname was counted. Recount is required.', 409);
                }
                $delta = $item->counted_quantity - $current;
                if ($delta === 0) {
                    continue;
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
            $lockedOpname->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $lockedOpname->fresh()->load('items');
        });
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

    private function assertItem(?int $productId, ?int $variationId, ?int $subLocationId): void
    {
        if (($productId === null) === ($variationId === null) || ($subLocationId !== null && ! WarehouseSubLocation::query()->whereKey($subLocationId)->where('is_active', true)->exists())) {
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
