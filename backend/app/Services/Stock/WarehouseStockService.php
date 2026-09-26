<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseStockService
{
    public function sellableForProduct(int $agentId, int $productId): array
    {
        return app(SellableStockService::class)->forProduct($agentId, $productId);
    }

    public function sellableForVariation(int $agentId, int $variationId): array
    {
        return app(SellableStockService::class)->forVariation($agentId, $variationId);
    }

    public function sellableForTargets(int $agentId, Collection $productIds, Collection $variationIds): array
    {
        return app(SellableStockService::class)->forTargets($agentId, $productIds, $variationIds);
    }

    public function receiveFactoryStock(User $actor, int $productId, ?int $variationId, int $quantity, string $reference, ?string $note = null, ?int $approvedRequestId = null): WarehouseStock
    {
        $this->assertWarehouseActor($actor);
        if ($quantity <= 0) {
            throw new ApiException('Jumlah penerimaan harus lebih besar dari nol.', 422);
        }

        return DB::transaction(function () use ($actor, $productId, $variationId, $quantity, $reference, $note, $approvedRequestId) {
            $this->assertTarget($productId, $variationId);
            if ($approvedRequestId) {
                $existing = StockMovement::query()->where('agent_id', $actor->agent_id)->where('warehouse_stock_request_id', $approvedRequestId)->first();
                if ($existing) {
                    return WarehouseStock::withoutGlobalScopes()->whereKey($existing->reference_id)->firstOrFail();
                }
            } else {
                $existing = StockMovement::query()->where('agent_id', $actor->agent_id)->where('type', 'factory_in')
                    ->where('reference_type', 'factory_receipt')->where('note', $reference)
                    ->where('product_id', $variationId ? null : $productId)->where('product_variation_id', $variationId)->first();
                if ($existing) {
                    return WarehouseStock::withoutGlobalScopes()->whereKey($existing->reference_id)->firstOrFail();
                }
            }
            $stock = $this->lockedStock($actor->agent_id, $productId, $variationId, 'transit');
            $before = $stock->quantity;
            $stock->increment('quantity', $quantity);
            StockMovement::create(['agent_id' => $actor->agent_id, 'product_id' => $variationId ? null : $productId, 'product_variation_id' => $variationId, 'type' => 'factory_in', 'quantity' => $quantity, 'stock_type' => 'transit', 'reference_type' => 'factory_receipt', 'reference_id' => $stock->id, 'note' => $reference, 'warehouse_stock_request_id' => $approvedRequestId, 'created_by' => $actor->id]);

            return $stock->fresh()->setAttribute('before_quantity', $before)->setAttribute('note', $note);
        });
    }

    public function adjustFactoryPlan(User $actor, int $productId, ?int $variationId, int $delta, string $reference, ?int $approvedRequestId = null, bool $fromApproval = false): WarehouseStock
    {
        $this->assertWarehouseActor($actor);
        if ($delta === 0) {
            throw new ApiException('Perubahan Plan Pabrik tidak boleh nol.', 422);
        }

        return DB::transaction(function () use ($actor, $productId, $variationId, $delta, $reference, $approvedRequestId, $fromApproval) {
            $this->assertTarget($productId, $variationId);
            if ($approvedRequestId) {
                $existing = StockMovement::query()->where('agent_id', $actor->agent_id)->where('warehouse_stock_request_id', $approvedRequestId)->first();
                if ($existing) {
                    return WarehouseStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('stock_type', 'factory_plan')->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))->when(! $variationId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))->firstOrFail();
                }
            }
            $setting = WarehouseSetting::query()->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrCreate(['agent_id' => $actor->agent_id]);
            if (! $fromApproval && ! $setting->factory_plan_enabled) {
                throw new ApiException(__('messages.warehouse.factory_plan_disabled'), 422);
            }
            $stock = $this->lockedStock($actor->agent_id, $productId, $variationId, 'factory_plan');
            $newQuantity = $stock->quantity + $delta;
            $reserved = $variationId
                ? (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('product_variation_id', $variationId)->value('quantity_reserved') ?? 0)
                : (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('product_id', $productId)->value('quantity_reserved') ?? 0);
            $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('stock_type', 'transit')->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->value('quantity');
            if ($newQuantity < 0 || $transit + $newQuantity < $reserved) {
                throw new ApiException('Perubahan Plan Pabrik melampaui komitmen reservasi.', 422);
            }
            $stock->update(['quantity' => $newQuantity]);
            StockMovement::create(['agent_id' => $actor->agent_id, 'product_id' => $variationId ? null : $productId, 'product_variation_id' => $variationId, 'type' => $delta > 0 ? 'factory_plan_in' : 'factory_plan_out', 'quantity' => $delta, 'stock_type' => 'factory_plan', 'reference_type' => 'factory_plan', 'note' => $reference, 'warehouse_stock_request_id' => $approvedRequestId, 'created_by' => $actor->id]);

            return $stock->fresh();
        });
    }

    public function updateFactoryPlanSetting(User $actor, bool $enabled): WarehouseSetting
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat mengubah Plan Pabrik.', 403);
        }

        return DB::transaction(function () use ($actor, $enabled) {
            $setting = WarehouseSetting::query()->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrCreate(['agent_id' => $actor->agent_id]);
            if (! $enabled) {
                $transit = WarehouseStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('stock_type', 'transit')->get();
                foreach (ProductStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('quantity_reserved', '>', 0)->get() as $reserved) {
                    $physical = (int) $transit->first(fn ($row) => $row->product_id === $reserved->product_id && $row->product_variation_id === null)?->quantity;
                    if ((int) $reserved->quantity_reserved > $physical) {
                        throw new ApiException('Plan Pabrik tidak dapat dinonaktifkan karena ada komitmen reservasi.', 422);
                    }
                }
                foreach (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $actor->agent_id)->where('quantity_reserved', '>', 0)->get() as $reserved) {
                    $physical = (int) $transit->first(fn ($row) => $row->product_variation_id === $reserved->product_variation_id)?->quantity;
                    if ((int) $reserved->quantity_reserved > $physical) {
                        throw new ApiException('Plan Pabrik tidak dapat dinonaktifkan karena ada komitmen reservasi.', 422);
                    }
                }
            }
            $setting->update(['factory_plan_enabled' => $enabled]);

            return $setting->fresh();
        });
    }

    private function assertWarehouseActor(User $actor): void
    {
        if ((! $actor->isRole('gudang') && ! $actor->isRole('admin')) || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang/Admin dengan network valid.', 403);
        }
    }

    private function assertGudang(User $actor): void
    {
        $this->assertWarehouseActor($actor);
        if (! $actor->isRole('gudang')) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
    }

    private function assertTarget(int $productId, ?int $variationId): void
    {
        if (($variationId === null) === ($productId === 0)) {
            throw new ApiException('Pilih product atau variation.', 422);
        } if ($variationId && ! Product::query()->whereHas('variations', fn ($q) => $q->whereKey($variationId))->exists()) {
            throw new ApiException('Variation tidak valid.', 422);
        }
    }

    private function lockedStock(int $agentId, int $productId, ?int $variationId, string $type): WarehouseStock
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))->when(! $variationId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))->lockForUpdate()->first() ?? WarehouseStock::create(['agent_id' => $agentId, 'product_id' => $variationId ? null : $productId, 'product_variation_id' => $variationId, 'stock_type' => $type, 'quantity' => 0]);
    }
}
