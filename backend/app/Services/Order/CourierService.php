<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Logging\ActivityLogger;
use App\Services\Media\MediaService;
use App\Services\Stock\SubStockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Courier assignment + delivery progress, scoped per Shipment rather than per
 * Order — "satu order bisa beberapa kurir karena ada kemungkinan produk yang
 * bisa di reschedule" (Blueprint §Courier): a rescheduled item can split onto
 * its own Shipment (see OrderFulfillmentService::rescheduleItemDeliveryDate),
 * so different items on the same order may end up with different couriers,
 * each progressing independently.
 *
 * Unlike agent/sales fees (earned the instant an order is placed, regardless
 * of delivery outcome), a courier's fee is only earned once THEY actually
 * complete the delivery — recordCommissionsForItems() is only ever called
 * once a shipment (or, for an admin/agen bulk override, the whole order)
 * reaches 'terkirim'.
 */
class CourierService
{
    public function __construct(private readonly MediaService $mediaService, private readonly SubStockService $subStockService) {}

    /**
     * "Kurir wajib berada di bawah Agen" — a courier may only ever be
     * assigned to a shipment from their own agent's branch, never another
     * agent's (Blueprint: "Kurir tidak boleh... melihat data agen lain").
     *
     * Assignment integrity (A1-03): the authoritative mutation lock re-reads
     * and re-checks the shipment, its order, any existing executor and the
     * courier's eligibility BEFORE writing. Initial assignment only ever
     * lands on unassigned, in-progress ('diproses') work; assigning the SAME
     * courier again is an idempotent replay (same 200), while assigning a
     * DIFFERENT courier to an already-claimed shipment is rejected. Terminal
     * work ('dibayar'/'dikirim'/'terkirim'/cancelled) is never re-targeted.
     *
     * A1-01 (Koordinator self-executor): when the assigning actor is a
     * Koordinator-Kurir and the chosen "courier" is THEMSELVES, they become
     * the actual executor — their Courier profile (created on first
     * self-assignment) is recorded on the shipment, exactly like a normal
     * Kurir's. No dispatcher fee: commissions still only ever credit the
     * recorded executor on completion.
     */
    public function assignCourier(Shipment $shipment, Courier $courier, User $actor): Shipment
    {
        // R-03: a self_sub shipment is delivered by its owning Sales-Kurir-Sub — it must never be
        // handed to a normal Kurir (and the DB trigger refuses a courier_id on it anyway).
        if ($shipment->isSelfDelivery()) {
            throw new ApiException(__('messages.courier.self_delivery_no_courier'), 422);
        }

        $actorKoordinator = $actor->isRole('koordinator-kurir');

        return DB::transaction(function () use ($shipment, $courier, $actor, $actorKoordinator) {
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            // Raw query-builder value bypasses Order's own integer cast, so force
            // it here — otherwise $courier->agent_id (cast) !== string fails and a
            // valid office assignment is wrongly rejected on some driver builds.
            $agentId = (int) $order->agent_id;

            $isSelfExecutor = $actorKoordinator && (int) $courier->user_id === (int) $actor->id;
            if ($isSelfExecutor && $courier->agent_id !== $agentId) {
                throw new ApiException(__('messages.courier.invalid_assignment'), 422);
            }

            // Canonical eligibility under the lock (A1-03). Order-status + shipment-terminal
            // checks come FIRST: an idempotent replay is only ever valid on live, in-progress
            // work — never on a cancelled/completed order.
            if ($order->status !== 'diproses') {
                throw new ApiException(__('messages.courier.invalid_assignment'), 422);
            }
            if ($shipment->status !== 'pending') {
                throw new ApiException(__('messages.courier.invalid_assignment'), 422);
            }

            $courier = Courier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $executor = $courier->user_id !== null
                ? User::query()->whereKey($courier->user_id)->lockForUpdate()->first()
                : null;
            if ($courier->agent_id !== $agentId || ! $courier->is_active
                || ($courier->user_id !== null && (! $executor || $executor->status !== 'active'
                    || $executor->agent_id !== $agentId || ! $executor->isRole('kurir', 'koordinator-kurir')))) {
                throw new ApiException(__('messages.courier.invalid_assignment'), 422);
            }

            if ($shipment->courier_id !== null) {
                // The identical executor is already on it — idempotent replay, not an overwrite.
                if ((int) $shipment->courier_id === (int) $courier->id) {
                    ActivityLogger::log($actor->id, $shipment, 'shipment.courier_assigned', null, [
                        'order_id' => $shipment->order_id, 'courier_id' => $courier->id,
                        'previous_courier_id' => $shipment->courier_id, 'actor_role' => $actor->role?->slug,
                        'idempotent_replay' => true,
                    ]);

                    return $shipment->fresh();
                }
                throw new ApiException(__('messages.courier.already_assigned'), 422);
            }

            $this->assertAddedDemandFulfilled($order->id);

            $previousCourierId = $shipment->courier_id;
            $shipment->update(['courier_id' => $courier->id]);

            ActivityLogger::log($actor->id, $shipment, 'shipment.courier_assigned', null, [
                'order_id' => $shipment->order_id, 'courier_id' => $courier->id,
                'previous_courier_id' => $previousCourierId, 'actor_role' => $actor->role?->slug,
                'self_executor' => $isSelfExecutor,
            ]);

            return $shipment->fresh();
        });
    }

    /** Called only while the canonical Order boundary is held. SC-03-first must
     * retain Gudang access until its new demand is fulfilled, including pickup self-assignment. */
    private function assertAddedDemandFulfilled(int $orderId): void
    {
        $request = \App\Models\StockRequest::query()->where('order_id', $orderId)->lockForUpdate()->first();
        $items = OrderItem::query()->where('order_id', $orderId)->lockForUpdate()
            ->get(['id', 'idempotency_key', 'split_from_order_item_id']);
        $addedIds = $items->whereNotNull('idempotency_key')->pluck('id');
        // Rescheduling may split an added line; its descendants carry the same demand lineage.
        do {
            $before = $addedIds->count();
            $addedIds = $addedIds->merge($items->whereIn('split_from_order_item_id', $addedIds)->pluck('id'))->unique();
        } while ($addedIds->count() > $before);
        if ($addedIds->isEmpty()) {
            return;
        }
        $lines = $request ? \App\Models\StockRequestItem::query()->where('stock_request_id', $request->id)
            ->whereIn('order_item_id', $addedIds)->lockForUpdate()->get() : collect();
        if ($lines->count() !== $addedIds->count() || $lines->contains(fn ($line) => $line->remaining_qty > 0)) {
            throw new ApiException(__('messages.courier.sc03_fulfillment_pending'), 422);
        }
    }

    /**
     * A koordinator-kurir assigning THEMSELVES the executor role must have a
     * Courier profile (the same row a normal Kurir has: `courier_id` →
     * `Courier.user_id` drives ownership checks, receipts and the courier
     * fee). Created lazily on first self-assignment — a coordinator that
     * only dispatches other couriers stays profile-less. Never called for a
     * normal kurir (they get their profile at account creation) or for a
     * sales-kurir-sub (self_sub only). Same-agent enforced — a coordinator
     * can never mint a profile in another branch.
     */
    public function ensureSelfExecutorProfile(User $koordinator): Courier
    {
        if (! $koordinator->isRole('koordinator-kurir') || ! $koordinator->agent_id) {
            throw new ApiException(__('messages.user.unsupported_role_combination'), 422);
        }

        // UAT-006: two simultaneous "Ambil Pengiriman" taps by the SAME
        // coordinator can both observe "no profile yet". couriers_user_unique is
        // the real guard, so the loser's insert raises a duplicate-key error —
        // re-read the winner's row instead of surfacing a 500, so a self-take
        // race fails safely rather than crashing.
        try {
            return Courier::firstOrCreate([
                'user_id' => $koordinator->id,
            ], [
                'type' => 'internal', 'user_id' => $koordinator->id, 'agent_id' => $koordinator->agent_id,
                'name' => $koordinator->name, 'is_active' => true,
            ]);
        } catch (QueryException $e) {
            $existing = Courier::query()->where('user_id', $koordinator->id)->first();

            if (! $existing) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * A courier picking up an unassigned delivery self-assigns the moment
     * they mark it 'dikirim' — never overwrites an existing assignment to a
     * different courier (that shipment isn't theirs to take). A
     * koordinator-kurir may only ever claim a delivery assigned to their OWN
     * executor profile; they never take another courier's or an unassigned
     * standard delivery on pickup (dispatch is their job — self-execution is
     * a deliberate, audited assignment through the dispatch workspace).
     */
    public function selfAssignIfUnassigned(Shipment $shipment, User $kurirActor): void
    {
        $courier = $kurirActor->courierProfile;

        if (! $courier) {
            throw new ApiException(__('messages.courier.no_profile'), 422);
        }

        if ($kurirActor->isRole('koordinator-kurir')) {
            // Koordinators never silently grab a queue delivery on pickup — they can only
            // operate a shipment already explicitly assigned to their own executor profile.
            if ((int) $shipment->courier_id !== (int) $courier->id) {
                throw new ApiException(__('messages.courier.not_your_delivery'), 403);
            }

            return;
        }

        $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

        if ($shipment->courier_id === null) {
            $this->assertAddedDemandFulfilled($shipment->order_id);
            $shipment->update(['courier_id' => $courier->id]);
        } elseif ($shipment->courier_id !== $courier->id) {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }

    /**
     * R-03 / decision A: the shipment's delivery mode decides who may progress it.
     *
     *  - self_sub: ONLY the recorded Sales-Kurir-Sub owner (`self_delivered_by_user_id`) — a normal
     *    Kurir is never involved and a Courier profile is never required. Delivery proof stays
     *    mandatory to reach 'terkirim'.
     *  - standard: the normal Kurir workflow. A Sales-Kurir-Sub must NEVER operate it — they may
     *    only self-deliver Sub-sourced shipments — so this is rejected explicitly instead of
     *    relying on an accidental "missing courier profile" failure.
     *
     * A1-01 (Koordinator self-executor): a Koordinator-Kurir may progress a
     * standard shipment ONLY when it is assigned to their OWN executor
     * profile (`courier_id` → Courier.user_id === themselves). This is the
     * recorded-executor ownership check — the exact same rule a normal Kurir
     * passes on 'terkirim'. They never get dispatch-style authority over a
     * shipment a different courier is executing.
     */
    private function assertMayOperateShipment(Shipment $shipment, User $actor, string $newStatus, ?UploadedFile $proof): void
    {
        $shipment = $shipment->fresh();

        if ($shipment->isSelfDelivery()) {
            $isOwner = $actor->isRole('sales-kurir-sub')
                && $shipment->self_delivered_by_user_id !== null
                && (int) $shipment->self_delivered_by_user_id === (int) $actor->id;

            if (! $isOwner) {
                throw new ApiException(__('messages.courier.not_your_delivery'), 403);
            }

            // "Kurir harus memasukan bukti pengiriman jika ingin merubah status kirim dari dikirim
            // jadi terkirim" — a business rule, not just a frontend prompt.
            if ($newStatus === 'terkirim' && ! $proof) {
                throw new ApiException(__('messages.courier.delivery_proof_required'), 422);
            }

            return;
        }

        if ($actor->isRole('sales-kurir-sub')) {
            throw new ApiException(__('messages.courier.sales_kurir_sub_standard_forbidden'), 403);
        }

        // A1-01 (Koordinator self-executor): the Koordinator progresses delivery ONLY as the
        // recorded executor of THIS shipment (Shipment.courier_id -> Courier.user_id === self).
        // They never develop dispatch authority over another courier's assignment, and can never
        // claim an unassigned delivery through the pickup path (that is dispatch's job).
        if ($actor->isRole('koordinator-kurir')) {
            $assignedCourierUserId = $shipment->courier?->user_id;

            if ($assignedCourierUserId === null || (int) $assignedCourierUserId !== (int) $actor->id) {
                throw new ApiException(__('messages.courier.not_your_delivery'), 403);
            }

            if ($newStatus === 'terkirim' && ! $proof) {
                throw new ApiException(__('messages.courier.delivery_proof_required'), 422);
            }

            return;
        }

        if ($newStatus === 'dikirim' && $actor->isRole('kurir')) {
            $this->selfAssignIfUnassigned($shipment, $actor);
        }

        if ($newStatus === 'terkirim' && $actor->isRole('kurir')) {
            $assignedCourierUserId = $shipment->fresh()->courier?->user_id;

            if ($assignedCourierUserId !== null && $assignedCourierUserId !== $actor->id) {
                throw new ApiException(__('messages.courier.not_your_delivery'), 403);
            }

            if (! $proof) {
                throw new ApiException(__('messages.courier.delivery_proof_required'), 422);
            }
        }
    }

    /**
     * R-02: Sub-sourced goods sit in ONE Sales-Kurir-Sub's Sub Location, so only that owner may
     * mark them shipped — checked for every actor reaching this endpoint, office (agen/admin/
     * super_admin) included, since ShipmentPolicy::updateStatus lets them here too. The full
     * Sub-order delivery redesign is R-03.
     */
    private function assertMayShipSubStock(Shipment $shipment, User $actor): void
    {
        $foreignSub = OrderItem::query()->where('shipment_id', $shipment->id)->where('stock_source', 'sub')
            ->whereNotIn('sub_location_id', WarehouseSubLocation::withoutGlobalScopes()->where('owner_user_id', $actor->id)->select('id'))
            ->exists();
        if ($foreignSub) {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }

    /**
     * The kurir-facing counterpart of OrderService::updateStatus — moves every
     * item on THIS shipment (never its siblings on other shipments of the
     * same order) diproses->dikirim or dikirim->terkirim, keeps the
     * shipment's own tracking fields in sync, and recomputes the order's
     * denormalized overall status from all of its shipments' progress.
     */
    public function updateShipmentStatus(Shipment $shipment, string $newStatus, User $actor, ?UploadedFile $proof = null): Shipment
    {
        if (! in_array($newStatus, ['dikirim', 'terkirim'], true)) {
            throw new ApiException(__('messages.order.status_endpoint_required', ['status' => $newStatus]), 422);
        }

        return DB::transaction(function () use ($shipment, $newStatus, $actor, $proof) {
            // Serialize with dispatch/proposal/cancellation before touching the shipment.
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['diproses', 'dikirim'], true)) {
                throw new InvalidStateTransitionException($order->status, $newStatus, 'order');
            }
            if ($newStatus === 'dikirim') {
                $this->assertMayShipSubStock($shipment, $actor);
            }
            $this->assertMayOperateShipment($shipment, $actor, $newStatus, $proof);
            $items = OrderItem::query()->where('shipment_id', $shipment->id)->lockForUpdate()->get();

            $anyTransitioned = false;
            foreach ($items as $item) {
                if ($item->canTransitionTo($newStatus)) {
                    $item->update(['status' => $newStatus]);
                    if ($newStatus === 'dikirim' && $item->isSubSourced()) {
                        // Actual shipment of Sub-sourced goods: Sub physical -= qty, reservation consumed (once).
                        $this->subStockService->consume($item, $actor);
                    }
                    $anyTransitioned = true;
                }
            }

            if (! $anyTransitioned) {
                throw new InvalidStateTransitionException($items->first()?->status ?? $shipment->status, $newStatus, 'shipment');
            }

            if ($proof && $newStatus === 'terkirim') {
                $media = $this->mediaService->store($proof, 'shipment_proof', $shipment, $actor);
                $shipment->update(['proof_media_id' => $media->id]);
            }

            $this->syncShipmentAggregate($shipment);

            ActivityLogger::log($actor->id, $shipment, 'shipment.status_changed', null, [
                'order_id' => $shipment->order_id, 'to' => $newStatus, 'actor_role' => $actor->role?->slug,
            ]);

            if ($newStatus === 'terkirim') {
                // R-03: a self_sub shipment has no Courier — its courier fee (if any) is earned by
                // the Sales-Kurir-Sub who actually delivered it (self_delivered_by_user_id).
                $selfDeliverer = $shipment->isSelfDelivery() ? $shipment->selfDeliveredBy : null;
                $this->recordCommissionsForItems($items->fresh(), $shipment->courier, $selfDeliverer);
            }

            $this->recomputeOrderStatus($shipment->order_id);

            return $shipment->fresh();
        });
    }

    /**
     * Per-ITEM counterpart of {@see updateShipmentStatus} — the LOCKED courier work-unit model
     * (Human UAT: courier progress is PER OrderItem, never per Shipment).
     *
     * Transitions exactly ONE canonical OrderItem and leaves its shipment siblings untouched, even
     * though they share the order, the shipment, the delivery date and the courier. The shipment
     * stays the assignment/delivery container: executor authorization, the sub-stock guard, the
     * proof rule and the aggregate progress/order recomputation all reuse the exact same
     * shipment-level semantics as the bulk path — only the mutation itself is item-scoped.
     */
    public function updateOrderItemStatus(OrderItem $item, string $newStatus, User $actor, ?UploadedFile $proof = null): OrderItem
    {
        if (! in_array($newStatus, ['dikirim', 'terkirim'], true)) {
            throw new ApiException(__('messages.order.status_endpoint_required', ['status' => $newStatus]), 422);
        }

        return DB::transaction(function () use ($item, $newStatus, $actor, $proof) {
            // Same canonical lock order as the shipment path: Order → Shipment → item, so a
            // per-item action and a per-shipment action on the same unit serialize identically.
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($item->shipment_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            // The item must still be where the caller claims it is: same order, same shipment.
            // A forged id pointing at another item/order can never move anything else.
            if ((int) $item->order_id !== (int) $order->id || (int) $item->shipment_id !== (int) $shipment->id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 404);
            }

            if (! in_array($order->status, ['diproses', 'dikirim'], true)) {
                throw new InvalidStateTransitionException($order->status, $newStatus, 'order');
            }
            if ($newStatus === 'dikirim') {
                $this->assertMayShipSubStock($shipment, $actor);
            }
            $this->assertMayOperateShipment($shipment, $actor, $newStatus, $proof);

            if (! $item->canTransitionTo($newStatus)) {
                throw new InvalidStateTransitionException($item->status, $newStatus, 'order_item');
            }

            $item->update(['status' => $newStatus]);
            if ($newStatus === 'dikirim' && $item->isSubSourced()) {
                // Actual shipment of Sub-sourced goods: Sub physical -= qty, reservation consumed (once).
                $this->subStockService->consume($item, $actor);
            }

            if ($proof && $newStatus === 'terkirim') {
                $media = $this->mediaService->store($proof, 'shipment_proof', $shipment, $actor);
                $shipment->update(['proof_media_id' => $media->id]);
            }

            $this->syncShipmentAggregate($shipment);

            ActivityLogger::log($actor->id, $item, 'order_item.status_changed', null, [
                'order_id' => $shipment->order_id, 'shipment_id' => $shipment->id,
                'to' => $newStatus, 'actor_role' => $actor->role?->slug,
            ]);

            if ($newStatus === 'terkirim') {
                // Idempotent per item (recordCommissionsForItems skips an item that already has a
                // courier commission), so delivering siblings one by one never double-pays.
                $selfDeliverer = $shipment->isSelfDelivery() ? $shipment->selfDeliveredBy : null;
                $this->recordCommissionsForItems([$item->fresh()], $shipment->courier, $selfDeliverer);
            }

            $this->recomputeOrderStatus($shipment->order_id);

            return $item->fresh();
        });
    }

    /**
     * The Shipment's own `status`/`shipped_at`/`delivered_at` are a DERIVED aggregate over the items
     * riding it — never a copy of whichever single item happened to move last.
     *
     * This is what makes the per-ITEM courier work unit (Human UAT) safe: acting on Item A must not
     * tell the rest of the system that Item B has been delivered. Deriving from the mutated item's own
     * new status (the previous behaviour) marked a whole canonical shipment `delivered` — and set
     * `delivered_at` — after ONE of its items arrived. That had two real consequences:
     *   - `DeliveryVerificationService` gates on `delivered_at`, so an Admin could append a permanent
     *     "received" verification for a shipment whose sibling items were still sitting in `diproses`;
     *   - picking up a sibling afterwards REGRESSED the shipment from `delivered` back to `in_transit`
     *     while `delivered_at` stayed set, i.e. a unit simultaneously "in transit" and "delivered".
     *
     * The aggregate is monotonic: `delivered_at` is written once and never cleared, and a shipment that
     * has already been delivered is never walked back (a later `pengembalian`/`kembali` is a return of
     * goods that DID arrive, not an un-delivery).
     */
    public function syncShipmentAggregate(Shipment $shipment): void
    {
        // The caller already holds the canonical Order → Shipment → item boundary, so this is a plain
        // read of rows already serialized by that boundary (REPEATABLE READ safe: no other writer can
        // have committed a sibling item transition without the same Order lock first).
        $items = OrderItem::query()->where('shipment_id', $shipment->id)->pluck('status');

        if ($items->isEmpty()) {
            return;
        }

        $arrived = ['terkirim', 'pengembalian', 'kembali'];
        $left = ['dikirim', 'terkirim', 'pengembalian', 'kembali'];

        if ($items->every(fn ($status) => in_array($status, $arrived, true))) {
            $shipment->update([
                'status' => 'delivered',
                'delivered_at' => $shipment->delivered_at ?? now(),
            ]);

            return;
        }

        if ($items->contains(fn ($status) => in_array($status, $left, true))) {
            // Partially delivered: the unit is on the road, but it is NOT delivered. A shipment that
            // was already delivered stays delivered (returns do not un-deliver an arrival).
            if ($shipment->delivered_at === null) {
                $shipment->update([
                    'status' => 'in_transit',
                    'shipped_at' => $shipment->shipped_at ?? now(),
                ]);
            }
        }
    }

    /**
     * The order's own `status` is a denormalized read of its most-behind
     * shipment: 'terkirim' only once every (non-cancelled) item across every
     * shipment has arrived; 'dikirim' as soon as any one of them has left.
     * Never moves the order backwards, and never touches it before dispatch
     * (still 'diterima'/'dibatalkan') — those remain OrderService's calls.
     */
    private function recomputeOrderStatus(int $orderId): void
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        if (! $order || ! in_array($order->status, ['diproses', 'dikirim', 'terkirim'], true)) {
            return;
        }

        $items = OrderItem::query()->where('order_id', $orderId)
            ->whereNotIn('status', ['dibatalkan'])->get();

        if ($items->isEmpty()) {
            return;
        }

        $newStatus = match (true) {
            $items->every(fn (OrderItem $i) => in_array($i->status, ['terkirim', 'pengembalian', 'kembali'], true)) => 'terkirim',
            $items->contains(fn (OrderItem $i) => in_array($i->status, ['dikirim', 'terkirim', 'pengembalian', 'kembali'], true)) => 'dikirim',
            default => $order->status,
        };

        $rank = ['diproses' => 1, 'dikirim' => 2, 'terkirim' => 3];

        if (($rank[$newStatus] ?? 0) > ($rank[$order->status] ?? 0)) {
            $order->update(['status' => $newStatus]);
        }
    }

    /**
     * Bulk admin/agen/super_admin override path (OrderService::updateStatus)
     * — groups the order's items by whichever shipment each currently sits
     * on, crediting each group's own assigned courier. Idempotent: never
     * re-commissions an item a per-shipment action already paid out.
     */
    public function recordCommissionsOnDelivery(Order $order): void
    {
        $itemsByShipment = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('status', 'terkirim')
            ->with('shipment.courier')
            ->get()
            ->groupBy('shipment_id');

        foreach ($itemsByShipment as $items) {
            $shipment = $items->first()->shipment;
            $this->recordCommissionsForItems($items, $shipment?->courier, $shipment?->selfDeliveredBy);
        }
    }

    /**
     * @param  iterable<OrderItem>  $items
     * @param  ?User  $beneficiary  R-03 self-delivery: when present (a Sales-Kurir-Sub who
     *                              self-delivered a self_sub shipment), the courier fee is credited
     *                              to them instead of the (nonexistent) Courier profile owner.
     */
    public function recordCommissionsForItems(iterable $items, ?Courier $courier, ?User $beneficiary = null): void
    {
        $beneficiaryUserId = $beneficiary?->id ?? $courier?->user_id;

        if (! $beneficiaryUserId) {
            return;
        }

        foreach ($items as $item) {
            if ((float) $item->courier_fee_amount <= 0) {
                continue;
            }

            if (Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->exists()) {
                continue;
            }

            Commission::create([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'beneficiary_user_id' => $beneficiaryUserId,
                'beneficiary_role' => 'courier',
                'amount' => $item->courier_fee_amount,
                'status' => 'pending',
                'earned_at' => now(),
            ]);
        }
    }
}
