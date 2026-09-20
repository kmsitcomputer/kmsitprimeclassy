<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ActivityLog;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    private const PHYSICAL_BUCKETS = ['transit', 'shipping', 'sub'];

    public function create(User $actor, string $source, ?int $sourceSubLocationId, string $destination, ?int $destinationSubLocationId, array $items, ?string $reference = null, ?string $note = null): StockTransfer
    {
        $this->assertGudang($actor);
        $this->assertBucketPair($actor, $source, $sourceSubLocationId, $destination, $destinationSubLocationId);
        if ($items === []) {
            throw new ApiException('Transfer harus memiliki item.', 422);
        }

        return DB::transaction(function () use ($actor, $source, $sourceSubLocationId, $destination, $destinationSubLocationId, $items, $reference, $note) {
            $transfer = StockTransfer::create([
                'agent_id' => $actor->agent_id,
                'transfer_number' => $this->uniqueNumber('TRF'),
                'source_stock_type' => $source,
                'source_sub_location_id' => $sourceSubLocationId,
                'destination_stock_type' => $destination,
                'destination_sub_location_id' => $destinationSubLocationId,
                'status' => 'pending',
                'reference' => $reference,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            $seenTargets = [];
            foreach ($items as $item) {
                $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
                $variationId = isset($item['product_variation_id']) ? (int) $item['product_variation_id'] : null;
                if (($productId === null) === ($variationId === null) || (int) ($item['quantity'] ?? 0) <= 0) {
                    throw new ApiException('Setiap item harus memiliki tepat satu target dan jumlah positif.', 422);
                }
                $targetKey = $variationId ? 'v:'.$variationId : 'p:'.$productId;
                if (isset($seenTargets[$targetKey])) {
                    throw new ApiException('Target transfer tidak boleh duplikat.', 422);
                }
                $seenTargets[$targetKey] = true;
                $this->assertTarget($productId, $variationId);
                $transfer->items()->create(['product_id' => $productId, 'product_variation_id' => $variationId, 'quantity' => (int) $item['quantity']]);
            }

            return $transfer->load('items');
        });
    }

    public function complete(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->assertGudang($actor);

        return DB::transaction(function () use ($actor, $transfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->with('items')->whereKey($transfer->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($transfer->status === 'completed') {
                return $transfer->load(['items.product', 'items.variation', 'handover']);
            }
            if ($transfer->status !== 'pending') {
                throw new ApiException('Transfer ini tidak dapat diselesaikan.', 422);
            }

            $targets = [];
            foreach ($transfer->items as $item) {
                $targetKey = $item->product_variation_id ? 'v:'.$item->product_variation_id : 'p:'.$item->product_id;
                $targets[$targetKey] = ['item' => $item, 'key' => $targetKey];
            }
            ksort($targets);
            $locked = [];
            foreach ($targets as $target) {
                $item = $target['item'];
                $source = $this->stock($transfer->agent_id, $item, $transfer->source_stock_type, $transfer->source_sub_location_id)->lockForUpdate()->first();
                if (! $source || $source->quantity < $item->quantity) {
                    throw new ApiException('Stok sumber tidak mencukupi.', 422);
                }
                if ($transfer->source_stock_type === 'transit' && $transfer->destination_stock_type === 'sub') {
                    $this->assertTransitReservationSafety($transfer->agent_id, $item, $item->quantity, $source->quantity);
                }
                $destination = $this->stock($transfer->agent_id, $item, $transfer->destination_stock_type, $transfer->destination_sub_location_id)->lockForUpdate()->first();
                $locked[] = [$item, $source, $destination];
            }

            $handover = StockHandover::create([
                'agent_id' => $transfer->agent_id, 'stock_transfer_id' => $transfer->id,
                'handover_number' => $this->uniqueNumber('HOV'), 'handed_over_by' => $actor->id,
                'status' => 'handed_over', 'handed_over_at' => now(), 'note' => $transfer->note,
            ]);

            foreach ($locked as [$item, $source, $destination]) {
                $sourceBefore = $source->quantity;
                $source->decrement('quantity', $item->quantity);
                $destination ??= WarehouseStock::create([
                    'agent_id' => $transfer->agent_id,
                    'product_id' => $item->product_id,
                    'product_variation_id' => $item->product_variation_id,
                    'stock_type' => $transfer->destination_stock_type,
                    'sub_location_id' => $transfer->destination_sub_location_id,
                    'quantity' => 0,
                ]);
                $destinationBefore = $destination->quantity;
                $destination->increment('quantity', $item->quantity);
                $common = ['agent_id' => $transfer->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'transfer_id' => $transfer->id, 'handover_id' => $handover->id, 'reference_type' => StockTransfer::class, 'reference_id' => $transfer->id, 'created_by' => $actor->id];
                StockMovement::create($common + ['type' => 'transfer_out', 'stock_type' => $transfer->source_stock_type, 'sub_location_id' => $transfer->source_sub_location_id, 'counterpart_stock_type' => $transfer->destination_stock_type, 'quantity' => -$item->quantity, 'note' => "before={$sourceBefore};after=".($sourceBefore - $item->quantity)]);
                StockMovement::create($common + ['type' => 'transfer_in', 'stock_type' => $transfer->destination_stock_type, 'sub_location_id' => $transfer->destination_sub_location_id, 'counterpart_stock_type' => $transfer->source_stock_type, 'quantity' => $item->quantity, 'note' => "before={$destinationBefore};after=".($destinationBefore + $item->quantity)]);
            }

            $transfer->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
            ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => StockTransfer::class, 'subject_id' => $transfer->id, 'event' => 'stock_transfer.completed', 'properties' => ['agent_id' => $transfer->agent_id, 'handover_id' => $handover->id]]);

            return $transfer->fresh()->load(['items.product', 'items.variation', 'handover']);
        });
    }

    public function cancel(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->assertGudang($actor);
        $transfer = StockTransfer::withoutGlobalScopes()->whereKey($transfer->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
        if ($transfer->status !== 'pending') {
            throw new ApiException('Hanya transfer pending yang dapat dibatalkan.', 422);
        }
        $transfer->update(['status' => 'cancelled']);

        return $transfer->fresh();
    }

    private function stock(int $agentId, StockTransferItem $item, string $type, ?int $subLocationId)
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)
            ->where('sub_location_id', $subLocationId)
            ->when($item->product_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'));
    }

    private function assertBucketPair(User $actor, string $source, ?int $sourceSubLocationId, string $destination, ?int $destinationSubLocationId): void
    {
        if (! in_array($source, self::PHYSICAL_BUCKETS, true) || ! in_array($destination, self::PHYSICAL_BUCKETS, true) || $source === $destination && $source !== 'sub') {
            throw new ApiException('Transfer hanya mendukung perpindahan antar bucket fisik yang berbeda.', 422);
        }
        if ($source === 'sub' && $destination === 'shipping' || $source === 'shipping' && $destination === 'sub') {
            throw new ApiException('Transfer Shipping ke/dari Sub belum tersedia di fase ini.', 422);
        }
        if (($source === 'sub') !== ($sourceSubLocationId !== null) || ($destination === 'sub') !== ($destinationSubLocationId !== null)) {
            throw new ApiException('Sub location wajib sesuai dengan tipe bucket.', 422);
        }
        if ($source === 'sub' && $destination === 'sub' && $sourceSubLocationId === $destinationSubLocationId) {
            throw new ApiException('Sub location sumber dan tujuan harus berbeda.', 422);
        }
        foreach ([$sourceSubLocationId, $destinationSubLocationId] as $locationId) {
            if ($locationId !== null) {
                $location = WarehouseSubLocation::withoutGlobalScopes()->whereKey($locationId)->where('agent_id', $actor->agent_id)->first();
                if (! $location || ! $location->is_active) {
                    throw new ApiException('Sub location tidak aktif atau bukan milik network ini.', 422);
                }
            }
        }
    }

    private function assertTarget(?int $productId, ?int $variationId): void
    {
        if ($variationId && ! DB::table('product_variations')->where('id', $variationId)->whereNull('deleted_at')->exists()) {
            throw new ApiException('Variation tidak valid.', 422);
        }
        if ($productId && ! DB::table('products')->where('id', $productId)->whereNull('deleted_at')->exists()) {
            throw new ApiException('Product tidak valid.', 422);
        }
        if ($variationId && $productId && ! DB::table('product_variations')->where('id', $variationId)->where('product_id', $productId)->exists()) {
            throw new ApiException('Variation tidak terkait dengan Product.', 422);
        }
    }

    private function assertGudang(User $actor): void
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
    }

    private function assertTransitReservationSafety(int $agentId, StockTransferItem $item, int $quantity, int $currentTransit): void
    {
        $reserved = $item->product_variation_id
            ? (int) (ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $item->product_variation_id)->value('quantity_reserved') ?? 0)
            : (int) (ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $item->product_id)->value('quantity_reserved') ?? 0);
        $plan = $item->product_variation_id
            ? (int) (WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $item->product_variation_id)->whereNull('product_id')->where('stock_type', 'factory_plan')->value('quantity') ?? 0)
            : (int) (WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $item->product_id)->whereNull('product_variation_id')->where('stock_type', 'factory_plan')->value('quantity') ?? 0);
        $enabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
        if (($currentTransit - $quantity) + ($enabled ? $plan : 0) < $reserved) {
            throw new ApiException('Transfer akan mengurangi stok Transit di bawah komitmen reservasi.', 422);
        }
    }

    private function uniqueNumber(string $prefix): string
    {
        do {
            $number = $prefix.'-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));
        } while (DB::table($prefix === 'TRF' ? 'stock_transfers' : 'stock_handovers')->where($prefix === 'TRF' ? 'transfer_number' : 'handover_number', $number)->exists());

        return $number;
    }
}
