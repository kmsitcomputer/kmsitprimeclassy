<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockRequest;
use App\Models\WarehouseSubLocation;
use Illuminate\Support\Facades\DB;

class WarehouseStockRequestService
{
    public function __construct(private readonly WarehouseStockService $stocks) {}

    public function create(User $actor, ?int $productId, ?int $variationId, int $quantity, string $targetStockType, ?string $reference, ?string $note): WarehouseStockRequest
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        if ($quantity <= 0) {
            throw new ApiException('Jumlah permintaan harus lebih besar dari nol.', 422);
        }
        if (! in_array($targetStockType, ['transit', 'factory_plan'], true)) {
            throw new ApiException('Sumber stok tidak valid.', 422);
        }
        $this->assertTarget($productId, $variationId);

        return DB::transaction(function () use ($actor, $productId, $variationId, $quantity, $targetStockType, $reference, $note) {
            $request = WarehouseStockRequest::create([
                'agent_id' => $actor->agent_id,
                'request_type' => 'addition',
                'product_id' => $variationId ? null : $productId,
                'product_variation_id' => $variationId,
                'target_stock_type' => $targetStockType,
                'quantity' => $quantity,
                'reference' => $reference,
                'note' => $note,
                'status' => 'pending',
                'requested_by' => $actor->id,
            ]);

            return $request->fresh()->load(['product.images', 'variation.compositions.option', 'requester']);
        });
    }

    public function createSubAdjustment(User $actor, ?int $productId, ?int $variationId, int $subLocationId, int $delta, ?string $reference, ?string $note): WarehouseStockRequest
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        if ($delta === 0) {
            throw new ApiException('Selisih penyesuaian tidak boleh nol.', 422);
        }
        $this->assertTarget($productId, $variationId);
        $location = WarehouseSubLocation::withoutGlobalScopes()->whereKey($subLocationId)->where('agent_id', $actor->agent_id)->first();
        if (! $location || ! $location->is_active) {
            throw new ApiException('Sub location tidak aktif atau bukan milik network ini.', 422);
        }

        return DB::transaction(function () use ($actor, $productId, $variationId, $subLocationId, $delta, $reference, $note) {
            $request = WarehouseStockRequest::create([
                'agent_id' => $actor->agent_id,
                'request_type' => 'sub_adjustment',
                'product_id' => $variationId ? null : $productId,
                'product_variation_id' => $variationId,
                'target_stock_type' => 'sub',
                'sub_location_id' => $subLocationId,
                'quantity' => $delta,
                'reference' => $reference,
                'note' => $note,
                'status' => 'pending',
                'requested_by' => $actor->id,
            ]);

            return $request->fresh()->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
        });
    }

    public function approve(User $actor, WarehouseStockRequest $request): WarehouseStockRequest
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui permintaan stok.', 403);
        }

        return DB::transaction(function () use ($actor, $request) {
            $locked = WarehouseStockRequest::withoutGlobalScopes()
                ->whereKey($request->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                return $locked->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Permintaan sudah diproses.', 422);
            }
            if ($locked->request_type === 'sub_adjustment') {
                return $this->applySubAdjustment($actor, $locked);
            }
            if ($locked->request_type !== 'addition' || ! in_array($locked->target_stock_type, ['transit', 'factory_plan'], true)) {
                throw new ApiException('Permintaan ini tidak dapat disetujui pada Phase 1.', 422);
            }

            $reference = $locked->reference ?: 'STOCK-REQUEST-'.$locked->id;
            if ($locked->target_stock_type === 'transit') {
                $this->stocks->receiveFactoryStock($actor, (int) $locked->product_id, $locked->product_variation_id, $locked->quantity, $reference, $locked->note, $locked->id);
            } else {
                $this->stocks->adjustFactoryPlan($actor, (int) $locked->product_id, $locked->product_variation_id, $locked->quantity, $reference, $locked->id, true);
            }

            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $locked->fresh()->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
        });
    }

    private function applySubAdjustment(User $actor, WarehouseStockRequest $locked): WarehouseStockRequest
    {
        if ($locked->target_stock_type !== 'sub' || $locked->sub_location_id === null) {
            throw new ApiException('Permintaan Sub tidak valid.', 422);
        }
        $location = WarehouseSubLocation::withoutGlobalScopes()->whereKey($locked->sub_location_id)->where('agent_id', $locked->agent_id)->first();
        if (! $location || ! $location->is_active) {
            throw new ApiException('Sub location tidak aktif atau bukan milik network ini.', 422);
        }
        $existing = StockMovement::query()->where('agent_id', $locked->agent_id)->where('warehouse_stock_request_id', $locked->id)->first();
        if ($existing) {
            return $locked->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
        }
        $stock = WarehouseStock::withoutGlobalScopes()->where('agent_id', $locked->agent_id)->where('stock_type', 'sub')->where('sub_location_id', $locked->sub_location_id)
            ->when($locked->product_id, fn ($q) => $q->where('product_id', $locked->product_id)->whereNull('product_variation_id'))
            ->when($locked->product_variation_id, fn ($q) => $q->where('product_variation_id', $locked->product_variation_id)->whereNull('product_id'))
            ->lockForUpdate()->first();
        $before = $stock?->quantity ?? 0;
        $after = $before + $locked->quantity;
        if ($after < 0) {
            throw new ApiException('Stok Sub tidak boleh negatif.', 422);
        }
        $stock ??= WarehouseStock::create(['agent_id' => $locked->agent_id, 'product_id' => $locked->product_id, 'product_variation_id' => $locked->product_variation_id, 'stock_type' => 'sub', 'sub_location_id' => $locked->sub_location_id, 'quantity' => 0]);
        $stock->update(['quantity' => $after]);
        StockMovement::create(['agent_id' => $locked->agent_id, 'product_id' => $locked->product_id, 'product_variation_id' => $locked->product_variation_id, 'type' => 'sub_adjustment', 'quantity' => $locked->quantity, 'stock_type' => 'sub', 'sub_location_id' => $locked->sub_location_id, 'reference_type' => WarehouseStockRequest::class, 'reference_id' => $locked->id, 'note' => "before={$before};after={$after}", 'warehouse_stock_request_id' => $locked->id, 'created_by' => $actor->id]);

        $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $locked->fresh()->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
    }

    public function reject(User $actor, WarehouseStockRequest $request, string $reason): WarehouseStockRequest
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menolak permintaan stok.', 403);
        }

        return DB::transaction(function () use ($actor, $request, $reason) {
            $locked = WarehouseStockRequest::withoutGlobalScopes()->whereKey($request->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rejected') {
                return $locked->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Permintaan sudah diproses.', 422);
            }

            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $locked->fresh()->load(['product.images', 'variation.compositions.option', 'requester', 'subLocation']);
        });
    }

    private function assertTarget(?int $productId, ?int $variationId): void
    {
        if (($productId === null) === ($variationId === null)) {
            throw new ApiException('Pilih product atau variation.', 422);
        }
        if ($variationId !== null) {
            $variation = ProductVariation::query()->whereKey($variationId)->first();
            if (! $variation) {
                throw new ApiException('Variation tidak valid.', 422);
            }
        } elseif (Product::query()->whereKey($productId)->where('has_variations', true)->exists()) {
            throw new ApiException('Produk bervarian harus memilih variation.', 422);
        }
    }
}
