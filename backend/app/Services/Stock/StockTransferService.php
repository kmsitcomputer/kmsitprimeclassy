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

    /** Shipping only ever changes through an Admin-approved fulfillment proposal (Reserved must move with it) or a return/cancellation reversal. */
    private const SHIPPING_CLOSED_MESSAGE = 'Stok Shipping hanya berubah melalui fulfillment yang disetujui Admin, bukan transfer Gudang.';

    public function create(User $actor, string $source, ?int $sourceSubLocationId, string $destination, ?int $destinationSubLocationId, array $items, ?string $reference = null, ?string $note = null): StockTransfer
    {
        $this->assertGudang($actor);
        if ($source === 'factory_plan' || $destination === 'factory_plan') {
            throw new ApiException('Transfer Plan hanya melalui Stock Transfer Plan ke Transit dengan persetujuan Admin.', 422);
        }
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

    public function createPlanTransfer(User $actor, array $items, ?string $reference = null, ?string $note = null): StockTransfer
    {
        $this->assertGudang($actor);
        if (! $this->planEnabled($actor->agent_id)) {
            throw new ApiException('Plan Pabrik sedang nonaktif. Aktifkan Plan Pabrik sebelum membuat Stock Transfer.', 422);
        }

        return DB::transaction(function () use ($actor, $items, $reference, $note) {
            if ($items === []) {
                throw new ApiException('Transfer harus memiliki item.', 422);
            }
            $seenTargets = [];
            $normalized = [];
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
                $normalized[] = ['product_id' => $productId, 'product_variation_id' => $variationId, 'quantity' => (int) $item['quantity']];
            }
            $transfer = StockTransfer::create([
                'agent_id' => $actor->agent_id,
                'transfer_number' => $this->uniqueNumber('TRF'),
                'source_stock_type' => 'factory_plan',
                'source_sub_location_id' => null,
                'destination_stock_type' => 'transit',
                'destination_sub_location_id' => null,
                'status' => 'pending',
                'reference' => $reference,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
            foreach ($normalized as $row) {
                $transfer->items()->create($row);
            }

            return $transfer->load(['items.product', 'items.variation.compositions.option']);
        });
    }

    public function approve(User $actor, StockTransfer $transfer): StockTransfer
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui transfer.', 403);
        }

        return DB::transaction(function () use ($actor, $transfer) {
            $locked = StockTransfer::withoutGlobalScopes()->with('items')->whereKey($transfer->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return $locked->load(['items.product', 'items.variation.compositions.option', 'handover']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Transfer ini tidak dapat disetujui.', 422);
            }
            if ($locked->source_stock_type !== 'factory_plan' && $locked->destination_stock_type !== 'factory_plan') {
                return $this->executeApprovedTransfer($actor, $locked);
            }
            if ($locked->source_stock_type !== 'factory_plan' || $locked->destination_stock_type !== 'transit' || $locked->source_sub_location_id !== null || $locked->destination_sub_location_id !== null) {
                throw new ApiException('Transfer ini bukan Plan Pabrik ke Transit.', 422);
            }

            $targets = [];
            foreach ($locked->items as $item) {
                $targetKey = $item->product_variation_id ? 'v:'.$item->product_variation_id : 'p:'.$item->product_id;
                $targets[$targetKey] = $item;
            }
            ksort($targets);
            $pairs = [];
            foreach ($targets as $item) {
                $source = $this->stock($locked->agent_id, $item, 'factory_plan', null)->lockForUpdate()->first();
                if (! $source || $source->quantity < $item->quantity) {
                    throw new ApiException('Stok Plan Pabrik tidak mencukupi.', 422);
                }
                $destination = $this->stock($locked->agent_id, $item, 'transit', null)->lockForUpdate()->first();
                $pairs[] = [$item, $source, $destination];
            }

            foreach ($pairs as [$item, $source, $destination]) {
                $sourceBefore = $source->quantity;
                $source->decrement('quantity', $item->quantity);
                $destination ??= WarehouseStock::create([
                    'agent_id' => $locked->agent_id,
                    'product_id' => $item->product_id,
                    'product_variation_id' => $item->product_variation_id,
                    'stock_type' => 'transit',
                    'sub_location_id' => null,
                    'quantity' => 0,
                ]);
                $destinationBefore = $destination->quantity;
                $destination->increment('quantity', $item->quantity);
                $common = ['agent_id' => $locked->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'transfer_id' => $locked->id, 'reference_type' => StockTransfer::class, 'reference_id' => $locked->id, 'created_by' => $actor->id];
                StockMovement::create($common + ['type' => 'transfer_out', 'stock_type' => 'factory_plan', 'counterpart_stock_type' => 'transit', 'quantity' => -$item->quantity, 'note' => "before={$sourceBefore};after=".($sourceBefore - $item->quantity)]);
                StockMovement::create($common + ['type' => 'transfer_in', 'stock_type' => 'transit', 'counterpart_stock_type' => 'factory_plan', 'quantity' => $item->quantity, 'note' => "before={$destinationBefore};after=".($destinationBefore + $item->quantity)]);
            }

            $locked->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
            ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => StockTransfer::class, 'subject_id' => $locked->id, 'event' => 'stock_transfer.approved', 'properties' => ['agent_id' => $locked->agent_id]]);

            return $locked->fresh()->load(['items.product', 'items.variation.compositions.option', 'handover']);
        });
    }

    public function reject(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menolak transfer.', 403);
        }

        return DB::transaction(function () use ($actor, $transfer, $reason) {
            $locked = StockTransfer::withoutGlobalScopes()->whereKey($transfer->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rejected') {
                return $locked->load(['items.product', 'items.variation.compositions.option']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Transfer ini tidak dapat ditolak.', 422);
            }
            if ($locked->source_stock_type === 'factory_plan' || $locked->destination_stock_type === 'factory_plan') {
                if ($locked->source_stock_type !== 'factory_plan' || $locked->destination_stock_type !== 'transit' || $locked->source_sub_location_id !== null || $locked->destination_sub_location_id !== null) {
                    throw new ApiException('Transfer ini bukan Plan Pabrik ke Transit.', 422);
                }
            }
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $locked->fresh()->load(['items.product', 'items.variation.compositions.option']);
        });
    }

    private function planEnabled(int $agentId): bool
    {
        return (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);
    }

    /**
     * Executes an APPROVED non-Plan transfer (Transit <-> Sub, Sub <-> Sub). Only ever
     * reached from approve(), which already holds the transfer row lock inside its
     * transaction and has re-checked the status. Gudang can request and cancel but
     * can never execute a transfer itself — the Admin's approval is what moves stock.
     */
    private function executeApprovedTransfer(User $approver, StockTransfer $transfer): StockTransfer
    {
        if ($transfer->source_stock_type === 'shipping' || $transfer->destination_stock_type === 'shipping') {
            throw new ApiException(self::SHIPPING_CLOSED_MESSAGE, 422);
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
            'handover_number' => $this->uniqueNumber('HOV'), 'handed_over_by' => $transfer->created_by ?? $approver->id,
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
            $common = ['agent_id' => $transfer->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'transfer_id' => $transfer->id, 'handover_id' => $handover->id, 'reference_type' => StockTransfer::class, 'reference_id' => $transfer->id, 'created_by' => $approver->id];
            StockMovement::create($common + ['type' => 'transfer_out', 'stock_type' => $transfer->source_stock_type, 'sub_location_id' => $transfer->source_sub_location_id, 'counterpart_stock_type' => $transfer->destination_stock_type, 'quantity' => -$item->quantity, 'note' => "before={$sourceBefore};after=".($sourceBefore - $item->quantity)]);
            StockMovement::create($common + ['type' => 'transfer_in', 'stock_type' => $transfer->destination_stock_type, 'sub_location_id' => $transfer->destination_sub_location_id, 'counterpart_stock_type' => $transfer->source_stock_type, 'quantity' => $item->quantity, 'note' => "before={$destinationBefore};after=".($destinationBefore + $item->quantity)]);
        }

        $transfer->update(['status' => 'completed', 'completed_by' => $approver->id, 'completed_at' => now()]);
        ActivityLog::create(['causer_id' => $approver->id, 'subject_type' => StockTransfer::class, 'subject_id' => $transfer->id, 'event' => 'stock_transfer.approved', 'properties' => ['agent_id' => $transfer->agent_id, 'handover_id' => $handover->id]]);

        return $transfer->fresh()->load(['items.product', 'items.variation.compositions.option', 'handover']);
    }

    public function cancel(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->assertGudang($actor);

        return DB::transaction(function () use ($actor, $transfer) {
            $locked = StockTransfer::withoutGlobalScopes()->whereKey($transfer->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new ApiException('Hanya transfer pending yang dapat dibatalkan.', 422);
            }
            if ($locked->source_stock_type === 'factory_plan' || $locked->destination_stock_type === 'factory_plan') {
                throw new ApiException('Transfer Plan hanya melalui persetujuan Admin.', 422);
            }
            $locked->update(['status' => 'cancelled']);

            return $locked->fresh();
        });
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
        if ($source === 'shipping' || $destination === 'shipping') {
            throw new ApiException(self::SHIPPING_CLOSED_MESSAGE, 422);
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
