<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Models\OrderSalesReduction;
use App\Models\RefundObligation;
use App\Services\Logging\ActivityLogger;
use App\Services\Refund\Money;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundLedgerService;
use App\Services\Stock\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Per-item, post-delivery returns — "Konsumen dapat melakukan return setelah
 * item berstatus terkirim... Tidak wajib seluruh order dikembalikan. Jika
 * hanya satu item kembali, refund hanya berdasarkan item tersebut."
 *
 * Item lifecycle for a returned item: terkirim -> pengembalian (the moment a
 * return is requested) -> kembali (once Admin confirms the refund was
 * actually sent). A rejected request reverts the item back to terkirim —
 * the one deliberate exception to OrderItem::TRANSITIONS' forward-only
 * table, since this is a compensating action, not forward progress.
 */
class ReturnService
{
    private const EVIDENCE_DISK = 'public';

    private const EVIDENCE_DIRECTORY = 'returns/evidence';

    public function __construct(
        private readonly StockService $stockService,
        private readonly OrderFinancialSnapshotService $snapshotService,
        private readonly RefundLedgerService $refundLedger,
    ) {}

    /** @param  array<int, array{order_item_id:int, quantity:int, restock?:bool}>  $items */
    public function requestReturn(Order $order, User $actor, array $items, string $reason, ?UploadedFile $evidence): ReturnRequest
    {
        if (empty($items)) {
            throw new ApiException(__('messages.order.empty_items'), 422);
        }

        return DB::transaction(function () use ($order, $actor, $items, $reason, $evidence) {
            $evidencePath = $evidence?->store(self::EVIDENCE_DIRECTORY, self::EVIDENCE_DISK);

            $return = ReturnRequest::create([
                'order_id' => $order->id,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'evidence_path' => $evidencePath,
                'status' => 'requested',
            ]);

            $totalRefund = 0.0;

            foreach ($items as $line) {
                $item = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereKey($line['order_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($item->status !== 'terkirim') {
                    throw new ApiException(__('messages.return.item_not_delivered'), 422);
                }

                $quantity = (int) $line['quantity'];
                // `fulfilled_quantity` is ALREADY the current active/billed quantity (cancelled units
                // were removed from it), so cancelling must not be subtracted a second time — a
                // delivered active quantity of 2 permits 2 returns.
                $maxReturnable = $item->fulfilled_quantity - $item->returned_quantity;

                if ($quantity < 1 || $quantity > $maxReturnable) {
                    throw new ApiException(__('messages.return.invalid_quantity'), 422);
                }

                $refundAmount = $quantity * (float) $item->unit_price_snapshot;
                $totalRefund += $refundAmount;

                ReturnItem::create([
                    'return_id' => $return->id,
                    'order_item_id' => $item->id,
                    'quantity_returned' => $quantity,
                    'refund_amount' => $refundAmount,
                    'restock' => $line['restock'] ?? true,
                    'status' => 'pending',
                    'refund_status' => 'not_required',
                ]);

                $item->update([
                    'returned_quantity' => $item->returned_quantity + $quantity,
                    'status' => 'pengembalian',
                ]);
            }

            $return->update(['total_refund_amount' => $totalRefund]);

            ActivityLogger::log($actor->id, $return, 'return.requested', $reason, [
                'order_id' => $order->id, 'actor_role' => $actor->role?->slug, 'total_refund_amount' => $totalRefund,
            ]);

            return $return->fresh('items');
        });
    }

    public function review(ReturnRequest $return, User $actor, bool $approved, ?string $note = null): ReturnRequest
    {
        if (! in_array($return->status, ['requested', 'under_review'], true)) {
            throw new ApiException(__('messages.return.already_reviewed'), 422);
        }

        $orderId = ReturnRequest::query()->whereKey($return->id)->value('order_id');

        return DB::transaction(function () use ($return, $actor, $approved, $note, $orderId) {
            // Canonical Order-first lock order (AGENTS.md §11).
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $return = ReturnRequest::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! in_array($return->status, ['requested', 'under_review'], true)) {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            // Approval is a basis-requiring event: freeze the order's financial snapshot BEFORE
            // consuming any returned unit, so the same unit can never also become a cancellation
            // or shortfall refund.
            $snapshot = $approved
                ? $this->snapshotService->ensure(
                    $order,
                    OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL,
                    $actor,
                )
                : null;

            foreach ($return->items()->lockForUpdate()->get() as $returnItem) {
                if ($returnItem->status !== 'pending') {
                    continue;
                }

                $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->first();

                if ($approved) {
                    $returnItem->update(['status' => 'approved', 'refund_status' => 'pending']);

                    if ($orderItem && $snapshot) {
                        $this->recordReturnLedger($order, $orderItem, $returnItem, $snapshot, $actor);
                    }

                    // Physical restock is deliberately deferred until Gudang
                    // records the received condition inspection.
                } else {
                    $returnItem->update(['status' => 'rejected', 'refund_status' => 'not_required']);

                    if ($orderItem) {
                        // Compensating reversal, not forward progress — see class docblock.
                        $orderItem->update([
                            'returned_quantity' => max(0, $orderItem->returned_quantity - $returnItem->quantity_returned),
                            'status' => 'terkirim',
                        ]);
                    }
                }
            }

            $return->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            ActivityLogger::log($actor->id, $return, 'return.reviewed', $note, [
                'approved' => $approved, 'actor_role' => $actor->role?->slug,
            ]);

            return $return->fresh('items');
        });
    }

    public function inspectReturn(ReturnItem $returnItem, User $actor, int $received, int $good, int $damaged, ?string $note = null): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $received, $good, $damaged, $note) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();
            if (! $actor->isRole('gudang') || $actor->agent_id !== $order->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 403);
            }
            if ($returnItem->status !== 'approved' || $returnItem->inspected_at) {
                throw new ApiException('Return belum siap diinspeksi atau sudah diproses.', 422);
            }
            if ($received < 0 || $good < 0 || $damaged < 0 || $good + $damaged !== $received || $received > $returnItem->quantity_returned) {
                throw new ApiException('Jumlah inspeksi return tidak valid.', 422);
            }
            $returnItem->update(['quantity_received' => $received, 'good_quantity' => $good, 'damaged_quantity' => $damaged, 'condition_status' => $damaged > 0 && $good > 0 ? 'mixed' : ($damaged > 0 ? 'damaged' : 'good'), 'condition_note' => $note, 'inspected_by' => $actor->id, 'inspected_at' => now(), 'disposition_status' => 'pending_disposition']);

            return $returnItem->fresh();
        });
    }

    public function finalizeInspection(ReturnItem $returnItem, User $actor): ReturnItem
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($returnItem, $actor) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            if (in_array($returnItem->disposition_status, ['restocked', 'damaged_confirmed'], true)) {
                return $returnItem->fresh();
            }
            if ($returnItem->status !== 'approved' || $returnItem->disposition_status !== 'pending_disposition' || ! $returnItem->inspected_at) {
                throw new ApiException('Return belum siap difinalisasi.', 422);
            }
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();
            if ((int) $actor->agent_id !== (int) $order->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 404);
            }
            $good = (int) $returnItem->good_quantity;
            if ($good > 0) {
                if ($orderItem->isSubSourced()) {
                    // R-03 / decision F: a Sub-sourced customer return restocks the ORIGINAL Sub
                    // Location / Sub domain — never Agent Transit. Damaged quantity is excluded by
                    // construction ($good only).
                    $this->restockSubStock($order, $orderItem, $returnItem, $good, $actor);
                } else {
                    $query = WarehouseStock::withoutGlobalScopes()->where('agent_id', $order->agent_id)->where('stock_type', 'transit')->whereNull('sub_location_id')->when($orderItem->product_id, fn ($q) => $q->where('product_id', $orderItem->product_id)->whereNull('product_variation_id'))->when($orderItem->product_variation_id, fn ($q) => $q->where('product_variation_id', $orderItem->product_variation_id)->whereNull('product_id'))->lockForUpdate();
                    $stock = $query->first() ?? WarehouseStock::create(['agent_id' => $order->agent_id, 'product_id' => $orderItem->product_id, 'product_variation_id' => $orderItem->product_variation_id, 'stock_type' => 'transit', 'quantity' => 0]);
                    $before = $stock->quantity;
                    $stock->increment('quantity', $good);
                    StockMovement::create(['agent_id' => $order->agent_id, 'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id, 'product_variation_id' => $orderItem->product_variation_id, 'type' => 'return_restock', 'stock_type' => 'transit', 'quantity' => $good, 'reference_type' => ReturnItem::class, 'reference_id' => $returnItem->id, 'created_by' => $actor->id, 'note' => "before={$before};after=".($before + $good)]);
                }
            }
            $returnItem->update(['disposition_status' => $good > 0 ? 'restocked' : 'damaged_confirmed', 'restock_processed_at' => $good > 0 ? now() : null]);

            return $returnItem->fresh();
        });
    }

    /**
     * R-03 / decision F: restock good returned Sub-sourced units back into the ORIGINAL Sub Location
     * (`order_item.sub_location_id`) / Sub stock domain with a movement-backed, audited write.
     * If that location is missing or no longer active, the return is rejected explicitly — the goods
     * are NEVER silently redirected to Agent Transit.
     */
    private function restockSubStock(Order $order, OrderItem $orderItem, ReturnItem $returnItem, int $good, User $actor): void
    {
        if (! $orderItem->sub_location_id) {
            throw new ApiException(__('messages.return.sub_location_unavailable'), 422);
        }

        $location = WarehouseSubLocation::withoutGlobalScopes()
            ->whereKey($orderItem->sub_location_id)
            ->lockForUpdate()
            ->first();

        if (! $location || ! $location->is_active) {
            throw new ApiException(__('messages.return.sub_location_unavailable'), 422);
        }

        $query = WarehouseStock::withoutGlobalScopes()
            ->where('stock_type', 'sub')
            ->where('sub_location_id', $location->id)
            ->when($orderItem->product_variation_id, fn ($q) => $q->where('product_variation_id', $orderItem->product_variation_id)->whereNull('product_id'), fn ($q) => $q->where('product_id', $orderItem->product_id)->whereNull('product_variation_id'))
            ->lockForUpdate();

        $stock = $query->first() ?? WarehouseStock::create([
            'agent_id' => $order->agent_id,
            'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id,
            'product_variation_id' => $orderItem->product_variation_id,
            'stock_type' => 'sub',
            'sub_location_id' => $location->id,
            'quantity' => 0,
        ]);

        $before = $stock->quantity;
        $stock->increment('quantity', $good);

        StockMovement::create([
            'agent_id' => $order->agent_id,
            'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id,
            'product_variation_id' => $orderItem->product_variation_id,
            'type' => 'return_restock',
            'stock_type' => 'sub',
            'sub_location_id' => $location->id,
            'quantity' => $good,
            'reference_type' => ReturnItem::class,
            'reference_id' => $returnItem->id,
            'created_by' => $actor->id,
            'note' => "before={$before};after=".($before + $good),
        ]);
    }

    /**
     * Book one approved return line into the 360° ledger: consume the exact frozen units and
     * create the refund entitlement. total_amount is NOT recalculated for returns, so the
     * reduction is flagged applied_to_order_total=false (RefundMetrics derives B from it).
     */
    private function recordReturnLedger(
        Order $order,
        OrderItem $orderItem,
        ReturnItem $returnItem,
        \App\Models\OrderFinancialSnapshot $snapshot,
        User $actor,
    ): void {
        $quantity = (int) $returnItem->quantity_returned;
        $hasUnits = $this->snapshotService->availableUnits($snapshot, $orderItem->id) >= $quantity;

        // A line that was added/mutated after the snapshot has no frozen units to consume. Never
        // invent a unit: this return claim is unverifiable and must be reconciled by a human.
        $consumption = $hasUnits
            ? $this->snapshotService->consumeUnits(
                $snapshot,
                $orderItem->id,
                $quantity,
                'return_approval',
                $returnItem->id,
            )
            : ['reduction_cents' => 0, 'route' => 'missing_basis'];

        // returned_quantity was already incremented when the return was REQUESTED, so the
        // approved stage is (current - quantity) .. current — unique per return line.
        $stageTo = (int) $orderItem->returned_quantity;
        $stageFrom = $stageTo - $quantity;
        $key = 'return_item:'.$returnItem->id.':stage:'.$stageFrom.'-'.$stageTo;

        $attrs = [
            'source_type' => 'return_stage',
            'source_id' => $returnItem->id,
            'obligation_key' => $key,
            'snapshot_id' => $snapshot->id,
            'reason' => 'Return approved',
            'deferred_reason' => 'Return exceeds available cash',
            'approval_source' => 'return_approval',
        ];

        if ($snapshot->provenance === 'provisional_legacy' || $consumption['reduction_cents'] <= 0) {
            // Unallocatable historical basis: never invent an auto-refund. Book the entitlement as
            // a verification obligation (not capped by C) at the requested refund value.
            $eventCents = Money::cents($returnItem->refund_amount);

            if ($eventCents > 0) {
                $this->refundLedger->createVerificationObligation(
                    $order,
                    $attrs,
                    $eventCents,
                    $actor,
                    $snapshot->provenance === 'provisional_legacy' ? 'provisional_legacy' : 'forward',
                );
            }

            return;
        }

        OrderSalesReduction::create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'source_type' => 'return_stage',
            'source_id' => $returnItem->id,
            'amount' => Money::amount($consumption['reduction_cents']),
            'applied_to_order_total' => false,
            'provenance' => $snapshot->provenance === 'provisional_legacy' ? 'provisional_legacy' : 'forward',
            'reason' => 'Return approved',
            'created_by' => $actor->id,
        ]);

        $this->refundLedger->createForwardObligation(
            $order,
            $attrs,
            $consumption['reduction_cents'],
            $actor,
            autoApprove: true,
            provenance: $snapshot->provenance === 'provisional_legacy' ? 'provisional_legacy' : 'forward',
        );
    }

    /** Admin confirms the refund for one return line was actually sent — "sudah dikembalikan dana". */
    public function markItemRefunded(ReturnItem $returnItem, User $actor): ReturnItem
    {
        if ($returnItem->status !== 'approved' || $returnItem->refund_status === 'processed') {
            throw new ApiException(__('messages.return.already_reviewed'), 422);
        }

        // G17-D4: CRM-origin orders need recorded recipient + authorization evidence before money moves.
        \App\Services\Refund\RefundRecipientGuard::assertReady(
            \App\Models\Order::withoutGlobalScopes()->findOrFail($returnItem->returnRequest->order_id),
        );

        return DB::transaction(function () use ($returnItem, $actor) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();

            if ($returnItem->refund_status === 'processed') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            // A refund is a POST-PAID financial adjustment: the money can only
            // be given back once it was actually received. An unsettled DP (or
            // a COD order never marked paid) is refused, not silently refunded.
            $orderId = $returnItem->returnRequest?->order_id;
            $order = $orderId
                ? Order::query()->whereKey($orderId)->lockForUpdate()->first()
                : null;

            if ($order && ! $order->isFullyPaid()) {
                throw new ApiException(__('messages.payment.not_fully_paid'), 422);
            }

            // Settle the approved return entitlement through the ledger when it carries payable
            // value. A zero-amount deferred container is left for the Owner materialization path;
            // the legacy status flip below never moves money on its own.
            $obligation = $order
                ? RefundObligation::query()
                    ->where('order_id', $order->id)
                    ->where('source_type', 'return_stage')
                    ->where('source_id', $returnItem->id)
                    ->whereIn('status', RefundObligation::OPEN_STATUSES)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($obligation) {
                $this->refundLedger->settleLegacyConfirmed($obligation, $actor);
            }

            $returnItem->update(['refund_status' => 'processed']);

            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->first();

            if ($orderItem?->canTransitionTo('kembali') || $orderItem?->status === 'pengembalian') {
                $orderItem->update([
                    'status' => 'kembali',
                    'refund_quantity' => $orderItem->refund_quantity + $returnItem->quantity_returned,
                ]);
            }

            $return = $returnItem->returnRequest;
            if ($return && $return->items()->where('refund_status', '!=', 'processed')->where('status', 'approved')->doesntExist()) {
                $return->update(['status' => 'completed']);
            }

            // WS-8 (Owner 2026-10-30, FINAL): the ONLY point a refund becomes proven ("sudah
            // dikembalikan dana") for this line — exactly there the Sales fee for the refunded
            // units is cancelled, into the signed commission_adjustments ledger (`per unit ×
            // fee snapshot`). Rejected returns / unprocessed lines never run it; the service is
            // idempotent (unique (commission, return_item)) and clamps to the outstanding fee.
            app(\App\Services\Commission\SalesFeeRefundAdjuster::class)->adjustForProcessedReturn($returnItem, $actor);

            ActivityLogger::log($actor->id, $returnItem, 'return_item.refund_marked', null, [
                'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * "Kurir harus melakukan pickup" — the physical pickup acknowledgement by the ASSIGNED kurir
     * (never a claim: assertReturnAssignedToActor refuses unassigned/other-courier items),
     * separate from the eventual courierConfirmReturn().
     */
    public function pickupReturn(ReturnItem $returnItem, User $actor, ?string $note = null): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $note) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();

            if ($orderItem->status !== 'pengembalian') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            $this->assertReturnAssignedToActor($returnItem, $actor);

            if ($note !== null) {
                $returnItem->update(['condition_note' => $note]);
            }

            ActivityLogger::log($actor->id, $returnItem, 'return_item.picked_up', $note, [
                'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * Kurir's own action on a 'pengembalian' item — purely a physical-
     * logistics confirmation, never touching price/refund_status/payment
     * (Blueprint: "Kurir tidak boleh... mengubah fee... mengubah payment").
     * The actual refund money movement stays admin-only (markItemRefunded).
     *   - received=true:  pengembalian -> kembali (item physically collected).
     *   - received=false: pengembalian -> terkirim (customer didn't hand it
     *     over after all — same reversal exception as review(false)).
     */
    public function courierConfirmReturn(ReturnItem $returnItem, User $actor, bool $received): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $received) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();

            if ($orderItem->status !== 'pengembalian') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            $this->assertReturnAssignedToActor($returnItem, $actor);

            if ($received) {
                $orderItem->update(['status' => 'kembali']);
            } else {
                $returnItem->update(['status' => 'rejected', 'refund_status' => 'not_required']);
                $orderItem->update([
                    'returned_quantity' => max(0, $orderItem->returned_quantity - $returnItem->quantity_returned),
                    'status' => 'terkirim',
                ]);
            }

            ActivityLogger::log($actor->id, $returnItem, 'return_item.courier_confirmed', null, [
                'received' => $received, 'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * GAP-A (OD-P2-A): a scheduled return pickup a kurir still has to act on.
     * One derivation, shared by CourierDashboardController::pickupToday and
     * DashboardKpiService (kurir `return_pickups`): the item is scheduled for
     * TODAY (WIB business date), has NOT been physically collected yet (order
     * item still 'pengembalian'), and is in the kurir's own branch AND explicitly
     * assigned to this kurir's Courier profile
     * (same visibility as the return queue — no unclaimed return is any kurir's task).
     */
    public function scheduledPickupsQuery(User $actor)
    {
        $courierId = $actor->courierProfile?->id;

        return ReturnItem::query()
            ->whereIn('return_items.status', ['pending', 'approved'])
            ->whereDate('pickup_scheduled_for', now('Asia/Jakarta')->toDateString())
            ->whereHas('orderItem', function ($oiq) use ($actor) {
                $oiq->where('order_items.status', 'pengembalian')
                    ->whereHas('order', fn ($oq) => $oq->where('agent_id', $actor->agent_id));
            })
            // Assignment-only (Owner decision 2026-11-08): only returns assigned to this kurir.
            ->where('courier_id', $courierId ?? 0);
    }

    public function scheduledPickupsToday(User $actor): int
    {
        return $this->scheduledPickupsQuery($actor)->count();
    }

    /**
     * Owner decision (2026-11-08): no kurir self-claim on returns. The pickup executor is recorded
     * ONLY by an authorized office/dispatch actor via {@see assignPickupCourier}; the kurir's own
     * pickup/confirm actions require that recorded assignment to be THEIR Courier profile.
     */
    private function assertReturnAssignedToActor(ReturnItem $returnItem, User $actor): void
    {
        $courier = $actor->courierProfile;
        $agentId = $returnItem->orderItem->order?->agent_id;
        if ($actor->status !== 'active' || ! $actor->isRole('kurir')
            || ! $courier?->is_active || (int) $courier->agent_id !== (int) $agentId
            || (int) $actor->agent_id !== (int) $agentId) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if (! $courier) {
            throw new ApiException(__('messages.courier.no_profile'), 422);
        }

        if ($returnItem->courier_id === null || (int) $returnItem->courier_id !== (int) $courier->id) {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }

    /**
     * Dispatch/office assignment of a return pickup to an eligible courier of the SAME Agen branch.
     * Locks the return item + order item; refuses items no longer awaiting pickup. Re-assigning the
     * same courier is an idempotent replay; switching to a different courier requires the expected previous assignment
     * and is audited (previous_courier_id). Stale competing writes fail with 409. Revocation = assigning another courier.
     */
    public function assignPickupCourier(ReturnItem $returnItem, \App\Models\Courier $courier, User $actor, ?int $expectedCourierId = null): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $courier, $actor, $expectedCourierId) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($orderItem->order_id)->firstOrFail();

            if (! $actor->isRole('super_admin', 'agen', 'admin', 'koordinator-kurir')
                || (! $actor->isRole('super_admin') && (int) $order->agent_id !== (int) $actor->agent_id)) {
                throw new ApiException(__('messages.system.unauthorized_action'), 403);
            }
            if ($orderItem->status !== 'pengembalian' || ! in_array($returnItem->status, ['pending', 'approved'], true)) {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            $courier = \App\Models\Courier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $executor = $courier->user_id !== null ? User::query()->whereKey($courier->user_id)->lockForUpdate()->first() : null;
            if ((int) $courier->agent_id !== (int) $order->agent_id || ! $courier->is_active
                || ! $executor || $executor->status !== 'active' || (int) $executor->agent_id !== (int) $order->agent_id
                || ! $executor->isRole('kurir')) {
                throw new ApiException(__('messages.courier.invalid_assignment'), 422);
            }

            $previous = $returnItem->courier_id;
            if ($previous !== null && (int) $previous === (int) $courier->id) {
                return $returnItem;
            }
            if ($previous !== $expectedCourierId) {
                throw new ApiException('Penugasan retur telah berubah. Muat ulang sebelum menugaskan.', 409);
            }
            $returnItem->update(['courier_id' => $courier->id]);

            ActivityLogger::log($actor->id, $returnItem, 'return_item.courier_assigned', null, [
                'courier_id' => $courier->id, 'previous_courier_id' => $previous, 'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }
}
