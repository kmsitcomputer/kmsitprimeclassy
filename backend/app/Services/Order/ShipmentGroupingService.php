<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Logging\ActivityLogger;
use App\Services\Shipping\ShippingMethodClassifier;
use Illuminate\Support\Collection;
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
    /**
     * Provenance-vs-allocation: every fee snapshot written by the canonical even allocation is stamped with
     * this marker inside `provider_meta` (additive JSON key, no schema change). The marker is NOT quote
     * provenance and is excluded from every provenance signature; it only proves that a carrier's nonzero fee
     * is an ALLOCATION of the Order total, not an independent provider quote, so legacy quotes with incomplete
     * persisted provenance are not locked out by their own allocation (see openRouteQuoteSignature()).
     */
    private const ALLOCATION_MARKER = 'fee_allocation';

    private const ALLOCATION_METHOD = 'even_order_total_v1';

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
     * ONE canonical server-side shipping classification for an order, resolved from the canonical
     * `shipping_provider_code` snapshot of its RELEVANT shipments (a shipment holding a non-cancelled item,
     * or carrying nonzero fee evidence). There is no implicit fallback and no "first shipment wins":
     *   - a single recognised code      -> that classification;
     *   - no relevant shipment          -> UNKNOWN;
     *   - any null/empty/unknown code   -> UNKNOWN;
     *   - mixed/conflicting codes       -> UNKNOWN (fail closed).
     * Callers gate delivery-date mutation on ShippingMethodClassifier::allowsDeliveryDateChange().
     */
    public static function shippingClassification(Order $order): string
    {
        $itemShipmentIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', 'dibatalkan')
            ->whereNotNull('shipment_id')
            ->distinct()
            ->pluck('shipment_id');

        $classifications = Shipment::query()
            ->where('order_id', $order->id)
            ->where(function ($query) use ($itemShipmentIds) {
                $query->whereIn('id', $itemShipmentIds)->orWhere('shipping_fee_snapshot', '>', 0);
            })
            ->pluck('shipping_provider_code')
            ->map(fn ($code) => ShippingMethodClassifier::classify($code))
            ->unique();

        return $classifications->count() === 1 ? $classifications->first() : ShippingMethodClassifier::UNKNOWN;
    }

    /**
     * Round-5 preflight (F02B): runs BEFORE any date/membership mutation so a redistribution can never
     * rewrite quote evidence that cannot be proven safe. Transaction rollback remains the mandatory defence,
     * but the domain gate belongs in front of the business mutation.
     *
     *  - KURIR_ONLINE: prove every OpenRoute fee snapshot a redistribution would overwrite comes from the
     *    SAME canonical provider quote, and that no immutable committed group carries fee evidence;
     *  - FREE: assert the whole order stays zero-fee;
     *  - EKSPEDISI/PICKUP/UNKNOWN: denied by the caller's classification gate (no-op here).
     */
    public function preflightShippingFeeChange(Order $order, ?string $classification = null): void
    {
        $classification ??= self::shippingClassification($order);

        if (! ShippingMethodClassifier::allowsDeliveryDateChange($classification)) {
            return;
        }

        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        if ($classification === ShippingMethodClassifier::FREE) {
            $this->assertFreeShippingInvariant($order, $shipments);

            return;
        }

        $this->assertOpenRouteQuoteProvenanceConsistent($shipments);
        $this->assertNoCommittedNonzeroFee($shipments);
    }

    /**
     * Post-reschedule fee step. Order.shipping_fee_amount is the authoritative total and is NEVER changed
     * here: for Kurir Online the unchanged total is redistributed evenly across the active delivery-date
     * groups. Free delivery is already asserted all-zero by the preflight. Ekspedisi / Pickup / Unknown never
     * reach this method (denied before mutation).
     */
    public function applyShippingFeeAfterReschedule(Order $order, string $expectedClassification): void
    {
        // The reschedule was authorised under $expectedClassification. If the mutation itself changed the
        // order's classification (e.g. a new group inherited an inconsistent provider snapshot), silently
        // skipping the allocation would commit a date change with unreconciled fee evidence: fail closed
        // instead (the surrounding transaction rolls everything back).
        if (self::shippingClassification($order) !== $expectedClassification) {
            throw new ApiException(__('messages.fulfillment.unknown_shipping_method_cannot_reschedule'), 422);
        }

        if ($expectedClassification === ShippingMethodClassifier::KURIR_ONLINE) {
            $this->redistributeKurirOnlineShippingFee($order);
        }
    }

    /**
     * Human-approved rule: after a NEW valid Kurir Online (openroute) delivery-date change, the UNCHANGED
     * Order shipping fee is redistributed EVENLY across all ACTIVE delivery-date groups, deterministically
     * (exact integer arithmetic, no floating point). Called only from a valid reschedule — never as historical
     * repair, never for Ekspedisi, and it never rewrites immutable historical fee evidence.
     */
    public function redistributeKurirOnlineShippingFee(Order $order): void
    {
        if (self::shippingClassification($order) !== ShippingMethodClassifier::KURIR_ONLINE) {
            return;
        }

        // Consolidate first so there is exactly ONE mutable shipment per active date (idempotent).
        $this->reconcileOrder($order);

        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $mutable = $shipments->filter(fn (Shipment $s) => self::isMutable($s));

        // Defence in depth (the preflight already proved this): a committed/historical shipment carrying a
        // nonzero fee would be double-counted, or would have to be rewritten — refuse instead of corrupting
        // immutable history.
        $this->assertNoCommittedNonzeroFee($shipments);

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

        // EXACT integer minor-unit arithmetic (never float). The whole-rupiah domain is preserved
        // (Human-approved examples: 1/3 -> 1,0,0); any sub-rupiah remainder rides the first group so the
        // total is conserved exactly to the cent.
        $totalMinor = self::toMinorUnits($order->shipping_fee_amount);
        $wholeRupiah = intdiv($totalMinor, 100);
        $fractionCents = $totalMinor % 100;
        $groups = count($carriers);
        $base = intdiv($wholeRupiah, $groups);
        $remainder = $wholeRupiah % $groups;

        $assigned = [];
        $index = 0;
        foreach ($carriers as $id) {
            $rupiah = $base + ($index < $remainder ? 1 : 0);
            $cents = ($rupiah * 100) + ($index === 0 ? $fractionCents : 0);
            $assigned[$id] = true;
            $carrier = $mutable->firstWhere('id', $id);
            $meta = is_array($carrier->provider_meta) ? $carrier->provider_meta : [];
            $meta[self::ALLOCATION_MARKER] = ['method' => self::ALLOCATION_METHOD, 'order_fee' => self::decimalFromMinor($totalMinor)];
            Shipment::query()->whereKey($id)->update([
                'shipping_fee_snapshot' => self::decimalFromMinor($cents),
                'provider_meta' => $meta,
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
            // An EMPTY shell is reusable only when its provider snapshot matches the group being created: reusing
            // a shell with a null/foreign provider code would silently turn the order's shipping
            // classification into UNKNOWN/mixed the moment an item lands on it.
            if ($dates === [] && $empty === null && $candidate->shipping_provider_code === ($attributes['shipping_provider_code'] ?? null)) {
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
                // Multiple nonzero carriers that disagree (value or material identity) fail closed rather than
                // guessing.
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
        if (self::toMinorUnits($from->shipping_fee_snapshot) <= 0) {
            return;
        }
        if (self::toMinorUnits($to->shipping_fee_snapshot) > 0) {
            return;
        }

        $to->update([
            'shipping_fee_snapshot' => $from->shipping_fee_snapshot,
            'rate_per_km' => $to->rate_per_km ?? $from->rate_per_km,
        ]);
    }

    /**
     * Provider-aware carrier evidence signature for a NONZERO fee snapshot. `null` means "no historical
     * carrier here" (the column is NOT NULL DEFAULT 0, so zero = no evidence). Compared by JSON equality in
     * assertCarriersConsistent(); only quote-defining fields are compared — incidental metadata (etd,
     * description, timestamps, response order) never changes quote identity and is excluded.
     *
     * @return array<string, mixed>|null
     */
    private static function feeCarrierSignature(Shipment $shipment): ?array
    {
        if (self::toMinorUnits($shipment->shipping_fee_snapshot) <= 0) {
            return null;
        }

        return match (ShippingMethodClassifier::classify($shipment->shipping_provider_code)) {
            ShippingMethodClassifier::EKSPEDISI => self::ekspedisiQuoteSignature($shipment),
            ShippingMethodClassifier::KURIR_ONLINE => self::openRouteQuoteSignature($shipment),
            default => self::unrecognisedQuoteSignature($shipment),
        };
    }

    /**
     * R5-F02A: for a nonzero RajaOngkir quote the MATERIAL identity is COURIER + SERVICE (RajaOngkir selects a
     * quote by that pair — see RajaOngkirProvider::quote()). Missing / empty / whitespace-only identity must
     * NEVER become an equivalence signature, so it carries a per-shipment `incomplete_identity` marker that
     * makes each such carrier conflict with every other one. A single legacy carrier is still preserved
     * (only one signature exists), but two cannot be silently deduped. Never guessed, never inferred from
     * price, never selected by shipment id.
     *
     * @return array<string, mixed>
     */
    private static function ekspedisiQuoteSignature(Shipment $shipment): array
    {
        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];
        $courier = self::normalizeMeta($meta['courier'] ?? null);
        $service = self::normalizeMeta($meta['service'] ?? null);

        $signature = [
            'provider' => 'rajaongkir',
            'shipping_provider_id' => $shipment->shipping_provider_id,
            'fee' => self::money($shipment->shipping_fee_snapshot),
            'rate_per_km' => $shipment->rate_per_km === null ? null : self::money($shipment->rate_per_km),
            'distance_km' => $shipment->distance_km === null ? null : self::money($shipment->distance_km),
            'courier' => $courier,
            'service' => $service,
        ];

        if ($courier === null || $service === null) {
            $signature['incomplete_identity'] = $shipment->id;
        }

        return $signature;
    }

    /**
     * R5-F02B: OpenRoute quote PROVENANCE signature. The persisted fee snapshot is DELIBERATELY excluded:
     * after a canonical equal-allocation the persisted fee is no longer the provider-calculated amount, so a
     * previous allocation must never masquerade as a new independent quote. Allocation only rewrites the fee;
     * every provenance field below (from OpenRouteProvider's own persisted meta — pricing rule, minimum
     * distance, minimum charge, chargeable distance — plus the persisted rate/distance/profile) stays stable,
     * which is exactly what lets a future operation prove the evidence is still the same quote.
     *
     * If the required material provenance is missing, two carriers cannot be proven equivalent: the
     * per-shipment `incomplete_identity` marker makes them fail closed, while a single legacy carrier is
     * preserved.
     *
     * @return array<string, mixed>
     */
    private static function openRouteQuoteSignature(Shipment $shipment): array
    {
        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];
        $pricing = is_array($meta['pricing'] ?? null) ? $meta['pricing'] : [];

        // NB: the persisted `rate_per_km` column is deliberately NOT compared — it is redundant with
        // pricing.price_per_km and is not carried onto a legitimate split group (ShipmentGroupingService
        // clones route/provider/meta but not the fee/rate snapshot), so comparing it would raise a false
        // conflict for the very allocation workflow this method protects. provider_meta is authoritative.
        $signature = [
            'provider' => 'openroute',
            'shipping_provider_id' => $shipment->shipping_provider_id,
            'distance_km' => $shipment->distance_km === null ? null : self::money($shipment->distance_km),
            'price_per_km' => self::metaNumber($pricing['price_per_km'] ?? null),
            'minimum_distance_km' => self::metaNumber($pricing['minimum_distance_km'] ?? null),
            'minimum_charge' => self::metaNumber($pricing['minimum_charge'] ?? null),
            'chargeable_distance_km' => self::metaNumber($meta['chargeable_distance_km'] ?? null),
            'routing_profile' => self::normalizeMeta($meta['routing_profile'] ?? null),
        ];

        if ($signature['price_per_km'] === null
            || $signature['minimum_distance_km'] === null
            || $signature['minimum_charge'] === null
            || $signature['chargeable_distance_km'] === null) {
            $allocation = self::allocationMarker($meta);
            if ($allocation !== null) {
                // Incomplete legacy provenance whose nonzero fee is a canonical ALLOCATION of the Order total:
                // equivalent only to carriers stamped by the same allocation AND holding byte-identical
                // remaining evidence (cloned from the same persisted quote). Still never equivalent to an
                // independent quote — those keep the per-shipment marker below.
                unset($meta[self::ALLOCATION_MARKER]);
                $signature['allocated_from'] = $allocation + ['evidence' => hash('sha256', json_encode(self::canonicalise($meta)))];
            } else {
                $signature['incomplete_identity'] = $shipment->id;
            }
        }

        return $signature;
    }

    /** @return array{method:string, order_fee:string}|null a well-formed canonical allocation marker */
    private static function allocationMarker(array $meta): ?array
    {
        $marker = $meta[self::ALLOCATION_MARKER] ?? null;
        if (! is_array($marker) || ($marker['method'] ?? null) !== self::ALLOCATION_METHOD || ! is_string($marker['order_fee'] ?? null)) {
            return null;
        }

        return ['method' => self::ALLOCATION_METHOD, 'order_fee' => $marker['order_fee']];
    }

    /** Recursively key-sorted copy so identical evidence always serialises identically. */
    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => self::canonicalise($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * A nonzero fee under a FREE / PICKUP / UNKNOWN code is already inconsistent data: it can never be
     * proven equivalent to another piece of evidence, so it is marked incomplete per shipment.
     *
     * @return array<string, mixed>
     */
    private static function unrecognisedQuoteSignature(Shipment $shipment): array
    {
        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];

        return [
            'provider' => (string) $shipment->shipping_provider_code,
            'shipping_provider_id' => $shipment->shipping_provider_id,
            'fee' => self::money($shipment->shipping_fee_snapshot),
            'rate_per_km' => $shipment->rate_per_km === null ? null : self::money($shipment->rate_per_km),
            'distance_km' => $shipment->distance_km === null ? null : self::money($shipment->distance_km),
            'courier' => self::normalizeMeta($meta['courier'] ?? null),
            'service' => self::normalizeMeta($meta['service'] ?? null),
            'incomplete_identity' => $shipment->id,
        ];
    }

    /**
     * R5-F02B cross-date preflight: every OpenRoute fee snapshot a redistribution would overwrite must come
     * from the SAME canonical provider quote. Differing provenance — or two carriers that each lack the
     * required provenance — is ambiguous historical evidence and fails closed BEFORE any mutation.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    private function assertOpenRouteQuoteProvenanceConsistent(Collection $shipments): void
    {
        $signatures = [];
        foreach ($shipments as $shipment) {
            if (self::toMinorUnits($shipment->shipping_fee_snapshot) <= 0) {
                continue;
            }
            $signatures[json_encode(self::openRouteQuoteSignature($shipment))] = true;
        }

        if (count($signatures) > 1) {
            throw new ApiException(__('messages.fulfillment.conflicting_shipping_fee_snapshots'), 422);
        }
    }

    /**
     * A committed/historical group carrying a nonzero fee would be double-counted or rewritten — refuse.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    private function assertNoCommittedNonzeroFee(Collection $shipments): void
    {
        foreach ($shipments as $shipment) {
            if (! self::isMutable($shipment) && self::toMinorUnits($shipment->shipping_fee_snapshot) > 0) {
                throw new ApiException(__('messages.fulfillment.cannot_redistribute_committed_fee'), 422);
            }
        }
    }

    /**
     * Human rule for `free`: a reschedule may proceed only while the whole order stays zero-fee. A nonzero
     * canonical fee — or any conflicting historical nonzero fee evidence — is inconsistent data and FAILS
     * CLOSED; it is never silently erased.
     *
     * @param  Collection<int, Shipment>|null  $shipments
     */
    private function assertFreeShippingInvariant(Order $order, ?Collection $shipments = null): void
    {
        if (self::toMinorUnits($order->shipping_fee_amount) !== 0) {
            throw new ApiException(__('messages.fulfillment.free_shipping_must_be_zero'), 422);
        }

        $shipments ??= Shipment::query()->where('order_id', $order->id)->get();
        foreach ($shipments as $shipment) {
            if (self::toMinorUnits($shipment->shipping_fee_snapshot) !== 0) {
                throw new ApiException(__('messages.fulfillment.free_shipping_must_be_zero'), 422);
            }
        }
    }

    /**
     * F02 fail-closed guard. Before consolidating historical carriers (merge group or shell deletion),
     * classify them:
     *   - none        -> zero semantics, safe;
     *   - exactly one -> preserve it;
     *   - multiple    -> only if every material signature is identical (same canonical snapshot / quote).
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
        // Clone the RELEVANT provider snapshot (a shipment holding an active item or carrying fee evidence),
        // never an irrelevant lower-id remnant: otherwise a new group could inherit a null/foreign code and
        // silently turn the order's classification into UNKNOWN/mixed.
        $activeShipmentIds = OrderItem::query()->where('order_id', $order->id)->where('status', '!=', 'dibatalkan')
            ->whereNotNull('shipment_id')->pluck('shipment_id');
        $reference = Shipment::query()->where('order_id', $order->id)
            ->where(fn ($q) => $q->whereIn('id', $activeShipmentIds)->orWhere('shipping_fee_snapshot', '>', 0))
            ->orderBy('id')->first()
            ?? $old
            ?? Shipment::query()->where('order_id', $order->id)->orderBy('id')->first();

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

    /**
     * Exact decimal-string -> integer MINOR units (cents). NEVER uses floating point for money. The DB
     * columns are unsigned DECIMAL(x,2), so the canonical representation is a non-negative number with at most
     * two significant fractional digits. Anything else — negative, exponent, thousands separators, a nonzero
     * third decimal that would be truncated, an absurd magnitude — is malformed and fails safely (422); it is
     * never silently converted.
     */
    private static function toMinorUnits(mixed $value): int
    {
        if (is_int($value)) {
            $string = (string) $value;
        } elseif ($value === null || (is_string($value) && trim($value) === '')) {
            return 0;
        } elseif (is_string($value) || is_float($value)) {
            $string = trim((string) $value);
        } else {
            throw new ApiException(__('messages.fulfillment.invalid_monetary_amount'), 422);
        }

        if (! preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $string, $m)) {
            throw new ApiException(__('messages.fulfillment.invalid_monetary_amount'), 422);
        }

        $fraction = $m[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new ApiException(__('messages.fulfillment.invalid_monetary_amount'), 422);
        }

        return ((int) $m[1]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** Integer minor units -> canonical 2-decimal string. */
    private static function decimalFromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }

    /** Exact canonical money string for a DECIMAL value (used only for comparison signatures). */
    private static function money(mixed $value): string
    {
        return self::decimalFromMinor(self::toMinorUnits($value));
    }

    /**
     * Exact canonical decimal string for an OpenRoute meta numeric (comparison identity only — never money
     * allocation). Unlike money() it keeps every fractional digit, so provenance that differs below two
     * decimals is still a difference. Absent / non-numeric / non-finite values are `null` (= incomplete).
     */
    private static function metaNumber(mixed $value): ?string
    {
        if (is_int($value)) {
            $string = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            // 6 fractional digits is far beyond the provider's own precision (2dp config, 2dp distance).
            $string = number_format($value, 6, '.', '');
        } elseif (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
            $string = trim($value);
        } else {
            return null;
        }

        if (str_contains($string, '.')) {
            $string = rtrim(rtrim($string, '0'), '.');
        }

        return in_array($string, ['', '-0'], true) ? '0' : $string;
    }

    /** Normalize a material metadata string for comparison without erasing real service differences. */
    private static function normalizeMeta(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null; // arrays / objects are malformed identity -> incomplete, never coerced
        }

        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        return $normalized === '' ? null : $normalized;
    }
}
