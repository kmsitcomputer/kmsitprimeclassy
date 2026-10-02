<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
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
            // F03: an assigned tracking/resi number is operational commitment — the shipment already has a
            // real-world identity. It must never be merged, reused as a mutable shell or deleted by
            // regroup/reschedule cleanup, even while still `pending` and unassigned to a courier.
            && blank($shipment->tracking_number)
            && ! $shipment->deliveryVerifications()->exists();
    }

    /**
     * F04: a HISTORICAL shipment has finalized delivery evidence — its identity AND its content (membership,
     * represented quantity, date meaning) are immutable history. Derived from SHIPMENT-level fields, never
     * from the item status (an inconsistent/stale item status must not defeat proof/verification/delivery).
     * This is a strict superset of the non-mutable states that still allow pre-delivery regrouping.
     */
    public static function isHistorical(Shipment $shipment): bool
    {
        return $shipment->delivered_at !== null
            || $shipment->shipped_at !== null
            || $shipment->proof_media_id !== null
            || in_array($shipment->status, ['picked_up', 'in_transit', 'delivered', 'failed'], true)
            || $shipment->deliveryVerifications()->exists();
    }

    /**
     * Canonical shipping-method snapshot (server-derived at checkout, `ShippingQuoteService` $labels):
     * `rajaongkir` = Ekspedisi, `openroute` = Kurir Online. Never inferred from UI strings.
     */
    public static function usesEkspedisi(Order $order): bool
    {
        return Shipment::query()->where('order_id', $order->id)
            ->where('shipping_provider_code', 'rajaongkir')->exists();
    }

    public static function usesKurirOnline(Order $order): bool
    {
        return Shipment::query()->where('order_id', $order->id)
            ->where('shipping_provider_code', 'openroute')->exists();
    }

    /**
     * Human-approved rule: after a NEW valid Kurir Online (openroute) delivery-date change, the UNCHANGED
     * Order shipping fee is redistributed EVENLY across all ACTIVE delivery-date groups, deterministically
     * (integer arithmetic, no floating point). Called only from a valid reschedule — never as historical
     * repair, never for Ekspedisi, and it never rewrites immutable historical fee evidence.
     */
    public function redistributeKurirOnlineShippingFee(Order $order): void
    {
        if (! self::usesKurirOnline($order)) {
            return;
        }

        // Consolidate first so there is exactly ONE mutable shipment per active date (idempotent).
        $this->reconcileOrder($order);

        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $mutable = $shipments->filter(fn (Shipment $s) => self::isMutable($s));

        // A committed/historical shipment carrying a nonzero fee would be double-counted, or would have to be
        // rewritten — refuse instead of corrupting immutable history.
        foreach ($shipments as $shipment) {
            if (! self::isMutable($shipment) && (float) $shipment->shipping_fee_snapshot > 0.0) {
                throw new ApiException(__('messages.fulfillment.cannot_redistribute_committed_fee'), 422);
            }
        }

        $activeDates = OrderItem::query()->where('order_id', $order->id)
            ->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
            ->get(['requested_delivery_date'])
            ->map(fn ($i) => self::key($i->requested_delivery_date?->toDateString()))
            ->unique()->values()->all();

        $carriers = [];
        foreach ($activeDates as $date) {
            $carrier = $mutable->first(fn (Shipment $s) => $this->mutableActiveDate($s) === $date);
            if (! $carrier) {
                // The active group is only represented by a committed/historical shipment.
                throw new ApiException(__('messages.fulfillment.cannot_redistribute_committed_fee'), 422);
            }
            $carriers[$date] = $carrier->id;
        }

        if ($carriers === []) {
            return;
        }

        // Deterministic group order: requested_delivery_date ASC ('' sorts first), then explicit order.
        ksort($carriers);

        // Integer rupiah division (Human-approved examples): base = floor(total/N), the first (total % N)
        // groups take base + 1. Any sub-rupiah remainder (rare) rides on the first group so the total is
        // conserved exactly to the cent — no floating point arithmetic is used for the distribution.
        $totalCents = (int) round(((float) $order->shipping_fee_amount) * 100);
        $wholeRupiah = intdiv($totalCents, 100);
        $fractionCents = $totalCents - ($wholeRupiah * 100);
        $groups = count($carriers);
        $base = intdiv($wholeRupiah, $groups);
        $remainder = $wholeRupiah % $groups;

        $assigned = [];
        $index = 0;
        foreach ($carriers as $id) {
            $rupiah = $base + ($index < $remainder ? 1 : 0);
            $cents = ($rupiah * 100) + ($index === 0 ? $fractionCents : 0);
            $assigned[$id] = true;
            Shipment::query()->whereKey($id)->update([
                'shipping_fee_snapshot' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
            ]);
            $index++;
        }

        // Any other mutable shipment (empty shell / duplicate-date leftover) must not carry fee evidence.
        foreach ($mutable as $shipment) {
            if (! isset($assigned[$shipment->id])) {
                $shipment->update(['shipping_fee_snapshot' => 0]);
            }
        }
    }

    /** The single active requested date a mutable shipment represents, or null (empty / mixed / no active qty). */
    private function mutableActiveDate(Shipment $shipment): ?string
    {
        $dates = OrderItem::query()->where('shipment_id', $shipment->id)
            ->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
            ->get(['requested_delivery_date'])
            ->map(fn ($i) => self::key($i->requested_delivery_date?->toDateString()))
            ->unique()->values()->all();

        return count($dates) === 1 ? $dates[0] : null;
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
        // Canonical Order-first lock discipline (F05): guarantee the Order row is held before ANY child
        // shipment row is locked, regardless of whether the caller already took it (idempotent re-lock).
        Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();

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

        // F02: never guess when the two historical carriers that would be consolidated conflict.
        $this->assertCarriersConsistent([$old, $target]);

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
                // F02: validate the historical fee-carrier evidence for this consolidation BEFORE any mutation.
                // Multiple nonzero carriers that disagree (value or metadata) fail closed rather than guessing.
                $this->assertCarriersConsistent($shipments);
                $target = $this->survivorFor($shipments);
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

    /**
     * F02 conservation invariant: when a mutable shipment shell is merged/moved/deleted, a NONZERO
     * shipping-fee snapshot must survive on the surviving shipment — never lost, never double-counted.
     *
     * The column is `NOT NULL DEFAULT 0`, so the meaningful "carrier" is a nonzero fee, not a non-null one.
     *  - source has no nonzero fee  -> nothing to conserve;
     *  - target already carries a nonzero fee -> keep it (a second nonzero would double count);
     *  - otherwise transfer the source's fee (+ its rate metadata) to the target before the source is deleted.
     */
    private function carryFeeSnapshot(Shipment $from, Shipment $to): void
    {
        if ((float) $from->shipping_fee_snapshot <= 0.0) {
            return;
        }
        if ((float) $to->shipping_fee_snapshot > 0.0) {
            return;
        }

        $to->update([
            'shipping_fee_snapshot' => $from->shipping_fee_snapshot,
            'rate_per_km' => $to->rate_per_km ?? $from->rate_per_km,
        ]);
    }

    /**
     * F02 carrier evidence signature: a nonzero fee plus the quote metadata that makes up the snapshot.
     * `null` means "no historical carrier here" (the column is NOT NULL DEFAULT 0, so zero = no evidence).
     *
     * The material service identity lives in `provider_meta`: RajaOngkir selects a quote by COURIER + SERVICE,
     * so `25,000 JNE/REG` and `25,000 J&T/EZ` are NOT equivalent even though the number is the same. Only
     * quote-defining fields are compared — incidental metadata (etd, description, timestamps, debug data,
     * response order) never changes quote identity and is deliberately excluded.
     *
     * @return array<string, mixed>|null
     */
    private static function feeCarrierSignature(Shipment $shipment): ?array
    {
        if ((float) $shipment->shipping_fee_snapshot <= 0.0) {
            return null;
        }

        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];

        return [
            'fee' => self::money($shipment->shipping_fee_snapshot),
            'rate_per_km' => $shipment->rate_per_km === null ? null : self::money($shipment->rate_per_km),
            'shipping_provider_id' => $shipment->shipping_provider_id,
            'shipping_provider_code' => $shipment->shipping_provider_code,
            'distance_km' => $shipment->distance_km === null ? null : self::money($shipment->distance_km),
            'courier' => self::normalizeMeta($meta['courier'] ?? null),
            'service' => self::normalizeMeta($meta['service'] ?? null),
        ];
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Normalize a material metadata string for comparison without erasing real service differences. */
    private static function normalizeMeta(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * F02 fail-closed guard. Before consolidating historical carriers (merge group or shell deletion),
     * classify them:
     *   - none        -> zero semantics, safe;
     *   - exactly one -> preserve it;
     *   - multiple    -> only if numeric value AND fee metadata are ALL equivalent (same canonical snapshot).
     * Anything else is CONFLICTING historical evidence: abort without choosing a lower/higher id, summing or
     * discarding — the surrounding transaction rolls back.
     *
     * @param  iterable<Shipment>  $shipments
     */
    private function assertCarriersConsistent(iterable $shipments): void
    {
        $signatures = [];
        foreach ($shipments as $shipment) {
            $signature = self::feeCarrierSignature($shipment);
            if ($signature !== null) {
                $signatures[json_encode($signature)] = true;
            }
        }

        if (count($signatures) > 1) {
            throw new ApiException(__('messages.fulfillment.conflicting_shipping_fee_snapshots'), 422);
        }
    }

    /**
     * Deterministic survivor for a consolidation: the lowest-id carrier when one exists (so its historical
     * row/identity is preserved), otherwise the lowest-id shipment. Only ever called after
     * assertCarriersConsistent() proved the carriers (if several) are equivalent.
     *
     * @param  list<Shipment>  $shipments  id-ordered
     */
    private function survivorFor(array $shipments): Shipment
    {
        foreach ($shipments as $shipment) {
            if (self::feeCarrierSignature($shipment) !== null) {
                return $shipment;
            }
        }

        return $shipments[0];
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
