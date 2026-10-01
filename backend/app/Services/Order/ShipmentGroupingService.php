<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * LOCKED business rule (production UAT): shipment / delivery / resi grouping = ORDER + REQUESTED
 * DELIVERY DATE — never per product / per OrderItem.
 *
 * This is the ONE canonical resolver every shipment writer uses (checkout, SC-03 add-line, reschedule,
 * split). Scheduling truth stays `order_items.requested_delivery_date`; a shipment has no editable date of
 * its own — its date is DERIVED from its active (non-cancelled) items, so a second scheduling truth cannot
 * exist.
 *
 * Concurrency: every caller already holds (or here takes) the ORDER ROW lock, which serialises all shipment
 * grouping for that order. That is the canonical transactional discipline (Order -> ...), so two concurrent
 * writers can never create duplicate mutable shipments for the same Order + date, and no UNIQUE index (which
 * would fail on historical per-item rows) is needed.
 *
 * A shipment is MUTABLE (safe to regroup) only while it is still an uncommitted pending shell: status
 * `pending`, no courier, not shipped/delivered, no delivery proof, no delivery verification. Assigned,
 * in-flight and delivered shipments are operational/historical truth and are NEVER merged or re-dated; a
 * new/moved item then simply gets its own new mutable shipment (an explicit domain constraint).
 */
class ShipmentGroupingService
{
    public static function isMutable(Shipment $shipment): bool
    {
        return $shipment->status === 'pending'
            && $shipment->courier_id === null
            && $shipment->shipped_at === null
            && $shipment->delivered_at === null
            && $shipment->proof_media_id === null
            && ! $shipment->deliveryVerifications()->exists();
    }

    /**
     * Returns the order's mutable shipment for $date (same delivery mode / self-delivery actor), creating it
     * from $attributes when none exists. Candidates holding exactly that date are preferred over empty shells.
     *
     * @param  array<string, mixed>  $attributes  fields used only when a new shipment is created
     */
    public function resolveMutableShipmentFor(Order $order, ?string $date, array $attributes): Shipment
    {
        // The order lock is the serialisation point for ALL grouping of this order (idempotent re-lock).
        Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();

        $mode = $attributes['delivery_mode'] ?? Shipment::DELIVERY_MODE_STANDARD;
        $actor = $attributes['self_delivered_by_user_id'] ?? null;

        $candidates = Shipment::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->whereNull('shipped_at')
            ->whereNull('delivered_at')
            ->whereNull('proof_media_id')
            ->where('delivery_mode', $mode)
            ->when($actor === null, fn ($q) => $q->whereNull('self_delivered_by_user_id'), fn ($q) => $q->where('self_delivered_by_user_id', $actor))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (Shipment $s) => self::isMutable($s));

        $empty = null;
        foreach ($candidates as $candidate) {
            $dates = $this->activeDates($candidate);
            if ($dates === [self::key($date)]) {
                return $candidate;
            }
            if ($dates === [] && $empty === null) {
                $empty = $candidate;
            }
        }
        if ($empty) {
            return $empty;
        }

        return Shipment::create($attributes + ['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => $mode]);
    }

    /**
     * Places $item on its order's mutable shipment for the item's CURRENT requested date (creating one if
     * needed) and releases the shipment it came from when that is left an empty mutable shell.
     */
    public function assignItemToDateGroup(OrderItem $item, Order $order, User $actor, string $event = 'shipment.item_grouped'): Shipment
    {
        $old = $item->shipment_id ? Shipment::query()->whereKey($item->shipment_id)->lockForUpdate()->first() : null;
        if ($old) {
            $item->update(['shipment_id' => null]); // detach so the item does not count towards its old group
        }

        $before = Shipment::query()->where('order_id', $order->id)->count();
        $shipment = $this->resolveMutableShipmentFor($order, $item->requested_delivery_date?->toDateString(), $this->cloneAttributes($order, $item, $old));
        $item->update(['shipment_id' => $shipment->id]);

        if (Shipment::query()->where('order_id', $order->id)->count() > $before) {
            ActivityLogger::log($actor->id, $shipment, $event, null, [
                'order_id' => $order->id, 'order_item_id' => $item->id, 'delivery_date' => $item->requested_delivery_date?->toDateString(),
            ]);
        }

        $this->releaseIfEmpty($old, $shipment);

        // Lazy catch-up for legacy per-item shipments of this order: any other mutable same-date shipments are
        // merged now (idempotent; finalized/assigned shipments are never touched).
        if (Shipment::query()->where('order_id', $order->id)->count() > 1) {
            $this->reconcileOrder($order, $actor);
            $shipment = Shipment::query()->findOrFail($item->fresh()->shipment_id);
        }

        return $shipment;
    }

    /** Deletes an emptied MUTABLE shipment, carrying its shipping-fee snapshot onto the surviving one. */
    public function releaseIfEmpty(?Shipment $old, Shipment $target): void
    {
        if (! $old || $old->id === $target->id || OrderItem::query()->where('shipment_id', $old->id)->exists() || ! self::isMutable($old)) {
            return;
        }

        $this->carryFeeSnapshot($old, $target);
        $old->delete();
    }

    /**
     * Merges an order's MUTABLE same-date shipments into one (existing/legacy per-item shipments). Finalized,
     * assigned or in-flight shipments are never touched. Idempotent.
     *
     * @return array{merged:int, kept:int, skipped_mixed:int}
     */
    public function reconcileOrder(Order $order, ?User $actor = null): array
    {
        return DB::transaction(function () use ($order, $actor) {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();

            $mutable = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (Shipment $s) => self::isMutable($s));

            $groups = [];
            $skipped = 0;
            foreach ($mutable as $shipment) {
                $dates = $this->activeDates($shipment);
                if (count($dates) > 1) {
                    $skipped++;

                    continue;
                }
                $groups[$shipment->delivery_mode.'|'.($shipment->self_delivered_by_user_id ?? '').'|'.($dates[0] ?? '*')][] = $shipment;
            }

            $merged = 0;
            foreach ($groups as $shipments) {
                if (count($shipments) < 2) {
                    continue;
                }
                $target = collect($shipments)->first(fn ($s) => $s->shipping_fee_snapshot !== null) ?? $shipments[0];
                foreach ($shipments as $source) {
                    if ($source->id === $target->id) {
                        continue;
                    }
                    OrderItem::query()->where('shipment_id', $source->id)->update(['shipment_id' => $target->id]);
                    $this->carryFeeSnapshot($source, $target);
                    $source->delete();
                    $merged++;
                    if ($actor) {
                        ActivityLogger::log($actor->id, $target, 'shipment.merged_by_delivery_date', null, [
                            'order_id' => $order->id, 'merged_shipment_id' => $source->id,
                        ]);
                    }
                }
            }

            return ['merged' => $merged, 'kept' => $mutable->count() - $merged, 'skipped_mixed' => $skipped];
        });
    }

    /** @return list<string> distinct requested dates of the shipment's active items ('' = no date) */
    private function activeDates(Shipment $shipment): array
    {
        return OrderItem::query()
            ->where('shipment_id', $shipment->id)
            ->where('status', '!=', 'dibatalkan')
            ->get(['requested_delivery_date'])
            ->map(fn ($i) => self::key($i->requested_delivery_date?->toDateString()))
            ->unique()
            ->values()
            ->all();
    }

    private static function key(?string $date): string
    {
        return $date ?? '';
    }

    private function carryFeeSnapshot(Shipment $from, Shipment $to): void
    {
        if ($from->shipping_fee_snapshot !== null && $to->shipping_fee_snapshot === null) {
            $to->update(['shipping_fee_snapshot' => $from->shipping_fee_snapshot, 'rate_per_km' => $from->rate_per_km]);
        }
    }

    /** Destination/provider snapshot for a NEW group shipment, cloned from the order's original shipment. */
    private function cloneAttributes(Order $order, OrderItem $item, ?Shipment $old): array
    {
        $reference = Shipment::query()->where('order_id', $order->id)->orderBy('id')->first() ?? $old;

        $isSub = $item->isSubSourced();
        $selfDeliveredBy = null;
        if ($isSub) {
            $selfDeliveredBy = $reference?->self_delivered_by_user_id
                ?? ($item->sub_location_id
                    ? WarehouseSubLocation::withoutGlobalScopes()->whereKey($item->sub_location_id)->value('owner_user_id')
                    : null);
        }

        return [
            'shipping_provider_id' => $reference?->shipping_provider_id,
            'shipping_provider_code' => $reference?->shipping_provider_code,
            'origin_latitude' => $reference?->origin_latitude,
            'origin_longitude' => $reference?->origin_longitude,
            'destination_latitude' => $reference?->destination_latitude,
            'destination_longitude' => $reference?->destination_longitude,
            'distance_km' => $reference?->distance_km,
            'provider_meta' => $reference?->provider_meta,
            'delivery_mode' => $isSub ? Shipment::DELIVERY_MODE_SELF_SUB : Shipment::DELIVERY_MODE_STANDARD,
            'self_delivered_by_user_id' => $selfDeliveredBy,
        ];
    }
}
