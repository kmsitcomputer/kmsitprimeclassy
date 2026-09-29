<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;

/**
 * R-02: the Sub stock domain (a Sales-Kurir-Sub's own Sub Location). Completely separate from
 * Agent stock — Agent sellable is Transit + active Factory Plan - Agent Reserved and is NEVER
 * reduced again for Sub stock (Transit -> Sub already moved the units).
 *
 *   Sub Sellable = Sub Physical - Sub Reserved (active reservations)
 *
 * Lifecycle: order creation reserves (physical unchanged) -> shipment consumes (physical -= qty,
 * reservation consumed, once) -> cancellation releases (physical unchanged). Every method takes
 * the Sub stock row lock FIRST, so concurrent reserve/consume/transfer calls on one target
 * serialise and can neither oversell nor double-apply. Callers must be inside a transaction.
 */
class SubStockService
{
    public function physical(int $subLocationId, ?int $productId, ?int $variationId): int
    {
        return (int) $this->stockQuery($subLocationId, $productId, $variationId)->value('quantity');
    }

    /**
     * Active reservations for a target. $forWrite MUST be true whenever the result guards a write: a plain
     * SELECT is a snapshot read under REPEATABLE READ and can be older than the row lock we just waited for,
     * so it would let two concurrent reservations both see the same free quantity and oversell.
     */
    public function reserved(int $subLocationId, ?int $productId, ?int $variationId, bool $forWrite = false): int
    {
        $query = SubStockReservation::query()->where('sub_location_id', $subLocationId)->where('status', SubStockReservation::ACTIVE)
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId), fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'));

        return (int) ($forWrite ? $query->lockForUpdate()->pluck('quantity')->sum() : $query->sum('quantity'));
    }

    /** @return array{physical:int, reserved:int, sellable:int} */
    public function sellable(int $subLocationId, ?int $productId, ?int $variationId): array
    {
        $physical = $this->physical($subLocationId, $productId, $variationId);
        $reserved = $this->reserved($subLocationId, $productId, $variationId);

        return ['physical' => $physical, 'reserved' => $reserved, 'sellable' => max(0, $physical - $reserved)];
    }

    /**
     * Reserve Sub stock for an order item. Idempotent per order item: a retry returns the existing
     * reservation instead of reserving twice. Physical stock is NOT touched.
     */
    public function reserve(OrderItem $item, int $subLocationId, int $agentId, int $quantity, ?User $actor): SubStockReservation
    {
        if ($quantity < 1) {
            throw new ApiException(__('messages.order.invalid_quantity'), 422);
        }
        $existing = SubStockReservation::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }

        $productId = $item->product_variation_id ? null : $item->product_id;
        $stock = $this->stockQuery($subLocationId, $productId, $item->product_variation_id)->lockForUpdate()->first();
        $physical = (int) ($stock?->quantity ?? 0);
        $available = $physical - $this->reserved($subLocationId, $productId, $item->product_variation_id, forWrite: true);
        if (! $stock || $available < $quantity) {
            throw new InsufficientStockException($item->variation_label_snapshot ?? $item->product_name_snapshot ?? $item->sku_snapshot, max(0, $available), $quantity);
        }

        return SubStockReservation::create([
            'agent_id' => $agentId, 'sub_location_id' => $subLocationId, 'order_item_id' => $item->id,
            'product_id' => $productId, 'product_variation_id' => $item->product_variation_id,
            'quantity' => $quantity, 'status' => SubStockReservation::ACTIVE, 'reserved_by' => $actor?->id,
        ]);
    }

    /** Actual shipment: physical -= quantity and the reservation is consumed. Exactly once (idempotent). */
    public function consume(OrderItem $item, ?User $actor): ?SubStockReservation
    {
        $reservation = SubStockReservation::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
        if (! $reservation || $reservation->status === SubStockReservation::CONSUMED) {
            return $reservation;
        }
        if ($reservation->status !== SubStockReservation::ACTIVE) {
            throw new ApiException('Reservasi stok Sub sudah dilepas dan tidak dapat dikirim.', 422);
        }

        $stock = $this->stockQuery($reservation->sub_location_id, $reservation->product_id, $reservation->product_variation_id)->lockForUpdate()->first();
        if (! $stock || $stock->quantity < $reservation->quantity) {
            throw new ApiException('Stok fisik Sub tidak mencukupi untuk pengiriman.', 422);
        }
        $before = $stock->quantity;
        $stock->decrement('quantity', $reservation->quantity);
        $reservation->update(['status' => SubStockReservation::CONSUMED, 'consumed_at' => now(), 'consumed_by' => $actor?->id]);
        StockMovement::create([
            'agent_id' => $reservation->agent_id, 'product_id' => $reservation->product_id, 'product_variation_id' => $reservation->product_variation_id,
            'type' => 'out', 'stock_type' => 'sub', 'sub_location_id' => $reservation->sub_location_id, 'quantity' => -$reservation->quantity,
            'reference_type' => SubStockReservation::class, 'reference_id' => $reservation->id, 'created_by' => $actor?->id,
            'note' => "order_item={$item->id};before={$before};after=".($before - $reservation->quantity),
        ]);

        return $reservation->fresh();
    }

    /** Cancellation/rejection before shipment: reservation released, physical unchanged. Idempotent. */
    public function release(OrderItem $item, ?User $actor, string $reason): ?SubStockReservation
    {
        $reservation = SubStockReservation::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
        if (! $reservation || $reservation->status !== SubStockReservation::ACTIVE) {
            return $reservation;
        }
        // Serialise with reserve/consume/transfer on the same target.
        $this->stockQuery($reservation->sub_location_id, $reservation->product_id, $reservation->product_variation_id)->lockForUpdate()->first();
        $reservation->update(['status' => SubStockReservation::RELEASED, 'released_at' => now(), 'released_by' => $actor?->id, 'release_reason' => $reason]);

        return $reservation->fresh();
    }

    /**
     * Guard for every operation that lowers Sub physical stock outside a sale (Sub -> Transit return,
     * Sub -> Sub transfer, opname): it may never cut physical below the active reservations.
     * Caller must already hold the Sub stock row lock.
     */
    public function assertPhysicalDecreaseAllowed(int $subLocationId, ?int $productId, ?int $variationId, int $currentPhysical, int $decrease): void
    {
        if ($currentPhysical - $decrease < $this->reserved($subLocationId, $productId, $variationId, forWrite: true)) {
            throw new ApiException('Pengurangan stok Sub akan berada di bawah reservasi aktif.', 422);
        }
    }

    private function stockQuery(int $subLocationId, ?int $productId, ?int $variationId)
    {
        return WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $subLocationId)
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'), fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'));
    }
}
