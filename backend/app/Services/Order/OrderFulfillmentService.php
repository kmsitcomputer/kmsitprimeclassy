<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\OrderSalesReduction;
use App\Models\RefundObligation;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Logging\ActivityLogger;
use App\Services\Payment\PaymentService;
use App\Services\Refund\Money;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundLedgerService;
use App\Services\Refund\RefundMetrics;
use App\Services\Stock\StockService;
use App\Services\Stock\SubStockService;
use Illuminate\Support\Facades\DB;

/**
 * "Admin dapat mengubah jumlah item yang dapat dipenuhi sebelum order
 * berubah dari: diproses -> dikirim." Only ever allowed while the order is
 * still 'diproses' — the moment it moves to 'dikirim' this window is closed
 * (OrderService::updateStatus is the only thing that advances past
 * 'diproses', so guarding on order.status here is sufficient and exact).
 *
 * Reducing fulfilled_quantity releases the now-unneeded stock reservation
 * and creates an OrderItemAdjustment (a refund record — Blueprint example:
 * "Product A berkurang 1 -> REFUND RECORD untuk 1 x harga Product A").
 * Increasing it reserves more stock and creates an OrderAdditionalPayment
 * (transfer or COD). original_quantity/subtotal_snapshot/unit_price_snapshot
 * are never rewritten — "Jangan mengubah histori order asli" — only the
 * running fulfillment counters and the new ledger row change.
 */
class OrderFulfillmentService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly SubStockService $subStockService,
        private readonly PaymentService $paymentService,
        private readonly OrderTotalCalculator $orderTotalCalculator,
        private readonly OrderFinancialSnapshotService $snapshotService,
        private readonly RefundLedgerService $refundLedger,
        private readonly RefundMetrics $refundMetrics,
    ) {}

    public function adjustItemQuantity(OrderItem $item, int $newFulfilledQuantity, User $actor, string $reason, string $additionalPaymentMethod = 'transfer'): OrderItem
    {
        if ($newFulfilledQuantity < 0) {
            throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
        }

        return DB::transaction(function () use ($item, $newFulfilledQuantity, $actor, $reason, $additionalPaymentMethod) {
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            // D6 safe default: once the order-wide financial snapshot exists, no quantity/voucher/
            // discount/add-line change is allowed on ANY line (shortfall is handled inside the
            // snapshot itself and never mutates allocations).
            $this->snapshotService->assertQuantityAdjustable($order);

            if ($order->status !== 'diproses') {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
            }

            $delta = $newFulfilledQuantity - $item->fulfilled_quantity;

            if ($delta === 0) {
                return $item;
            }

            if ($delta < 0) {
                $this->reduceFulfillment($order, $item, abs($delta), $actor, $reason);
            } else {
                $this->increaseFulfillment($order, $item, $delta, $actor, $reason, $additionalPaymentMethod);
            }

            ActivityLogger::log($actor->id, $item, 'order_item.fulfillment_adjusted', $reason, [
                'order_id' => $order->id, 'delta' => $delta, 'actor_role' => $actor->role?->slug,
            ]);

            return $item->fresh();
        });
    }

    /** Locks the order's Stock Request then this line's demand row (null for lines without Agent demand). */
    private function lockDemandForItem(Order $order, OrderItem $item): ?StockRequestItem
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
        if (! $request || $request->status === 'cancelled') {
            return null;
        }

        return StockRequestItem::query()->where('stock_request_id', $request->id)->where('order_item_id', $item->id)->lockForUpdate()->first();
    }

    /** Same pending/partial/fulfilled formula as the warehouse paths, from a locking read of the items. */
    private function refreshStockRequestStatus(int $stockRequestId): void
    {
        $request = StockRequest::withoutGlobalScopes()->whereKey($stockRequestId)->first();
        if (! $request || $request->status === 'cancelled') {
            return;
        }
        $items = StockRequestItem::query()->where('stock_request_id', $stockRequestId)->lockForUpdate()->get();
        $remaining = (int) $items->sum('remaining_qty');
        $fulfilled = (int) $items->sum('fulfilled_qty');
        $request->update([
            'status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'),
            'fulfilled_at' => $remaining === 0 ? ($request->fulfilled_at ?? now()) : null,
        ]);
    }

    /**
     * Why (if at all) the UNDELIVERED part of this line cannot be cancelled right now. Read-only; the
     * authoritative decision is re-made under locks in {@see cancelUndeliveredQuantity()}.
     */
    public function cancellationBlocker(Order $order, OrderItem $item): ?string
    {
        if (! in_array($order->status, ['diproses', 'dikirim'], true)) {
            return 'order_window_closed';
        }
        if ($item->isSubSourced()) {
            return 'sub_sourced'; // SKS keeps its own workflow
        }
        if ($item->status !== 'diproses') {
            // dikirim = in the courier's custody, terkirim = delivered, pengembalian/kembali/dibatalkan = other flows
            return 'not_undispatched';
        }
        if (\App\Services\Order\DeliveryRefusalService::hasOpenRefusal($item->id)) {
            return 'open_refusal';
        }
        if ($item->fulfilled_quantity < 1) {
            return 'nothing_to_cancel';
        }
        if ($item->additional_payment_id
            && OrderAdditionalPayment::query()->whereKey($item->additional_payment_id)->where('status', 'pending')->exists()) {
            return 'pending_additional_payment';
        }

        return null;
    }

    /**
     * REM-003 / Owner Option A — cancel part of the UNDELIVERED, UNDISPATCHED remainder of a line, also
     * after the first dispatch. A dedicated domain operation: it never goes through the quantity-freeze
     * guard (assertQuantityAdjustable), so it must carry its own: the item has to be `diproses` (not in
     * the courier's custody, not delivered, no open refusal), Agent-sourced, and the caller states the
     * quantity it believes is active (`$expectedQuantity`) — a stale or repeated request is a 409 and
     * changes nothing (no double cancellation).
     *
     * It reuses the canonical reduction: the frozen handover snapshot is only CONSUMED (units marked, never
     * re-allocated), reservation is released for un-accepted units while accepted ones return
     * Shipping → Transit, outstanding demand shrinks, totals are recalculated, and an overpayment becomes
     * a refund row exactly like Admin QTY. Payments, delivered/dispatched history are untouched.
     */
    public function cancelUndeliveredQuantity(OrderItem $item, int $quantity, int $expectedQuantity, User $actor, string $reason): OrderItem
    {
        return DB::transaction(function () use ($item, $quantity, $expectedQuantity, $actor, $reason) {
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $blocker = $this->cancellationBlocker($order, $item);
            if ($blocker !== null) {
                throw new ApiException(__('messages.cancellation.not_eligible'), 422, ['code' => $blocker]);
            }
            if ((int) $item->fulfilled_quantity !== $expectedQuantity) {
                throw new ApiException(__('messages.cancellation.stale'), 409, ['code' => 'quantity_changed']);
            }
            if ($quantity < 1 || $quantity > (int) $item->fulfilled_quantity) {
                throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
            }

            $this->reduceFulfillment($order, $item, $quantity, $actor, $reason);

            ActivityLogger::log($actor->id, $item, 'order_item.undelivered_cancelled', $reason, [
                'order_id' => $order->id, 'quantity_cancelled' => $quantity, 'remaining_active' => (int) $item->fresh()->fulfilled_quantity,
                'actor_role' => $actor->role?->slug,
            ]);

            // Whatever is left may now all be delivered (or nothing live remains): keep the order aggregate honest.
            app(CourierService::class)->recomputeOrderStatus($order->id);

            return $item->fresh();
        });
    }

    /**
     * Canonical rule (never "product removed = refund"): a refund is only
     * ever eligible when TOTAL VALID PAID already exceeds the order's
     * recalculated total — i.e. the reduction pushed the order into
     * overpayment. COD (paid_amount=0) and a still-partial DP (paid_amount <
     * new total) both correctly produce zero refund; only a fully-paid or
     * over-settled order can. The refund row is sized to the INCREMENTAL
     * overpayment this specific reduction created (newOverpaid -
     * previousOverpaid), so a second reduction on an already-overpaid order
     * never re-refunds the same money twice.
     */
    private function reduceFulfillment(Order $order, OrderItem $item, int $quantityReduced, User $actor, string $reason): void
    {
        // R-03 / MAJOR-7 + MAJOR-10: never reverse a quantity increase while its additional-payment
        // obligation is still outstanding. Normal read — NOT FOR UPDATE: OrderItem is already locked
        // (concurrent adjustments on this item serialise), and a stale pending read only produces a
        // conservative 422. Taking a financial-row lock here would create an
        // Order -> financial-row cycle against Keuangan settlement (deadlock).
        if ($item->additional_payment_id) {
            $additional = OrderAdditionalPayment::query()->whereKey($item->additional_payment_id)->first();
            if ($additional && $additional->status === 'pending') {
                throw new ApiException(__('messages.fulfillment.pending_additional_payment_blocks_reduction'), 422);
            }
        }

        // B-01: the fulfillment shortfall is a basis-requiring event. Snapshot BEFORE the
        // quantity changes so the frozen unit set is the pre-shortfall order basis; then consume
        // the shorted units so they can never also be returned.
        $fromQuantity = (int) $item->fulfilled_quantity;
        $snapshot = $this->snapshotService->ensure(
            $order,
            OrderFinancialSnapshotService::TRIGGER_FULFILLMENT_SHORTFALL,
            $actor,
        );

        // R-03 / MAJOR-8: the courier fee is not earned until delivery, so it must track the
        // currently active fulfilled quantity. Per-unit rate is derived from the PRE-mutation
        // snapshot (courier_fee_amount is already a historical snapshot — never catalog config).
        $courierPerUnit = $item->fulfilled_quantity > 0 ? (float) $item->courier_fee_amount / $item->fulfilled_quantity : 0.0;
        $releasedCourierFee = round($courierPerUnit * $quantityReduced, 2);

        // REM-001/002: lock order is Order → Stock Request → inventory. The line's warehouse demand is
        // locked BEFORE any stock row; only the UN-accepted part of the reduction releases reservation,
        // the accepted part (already consumed from the reservation, sitting in Shipping) goes back to Transit.
        $demand = $item->isSubSourced() ? null : $this->lockDemandForItem($order, $item);
        // F07: units a receipt reported damaged are held, not sellable — a reduction may take un-accepted
        // units that are NOT held, then accepted ones; it can never release a held unit (Admin resolves it first).
        $releasable = $demand ? max(0, (int) $demand->remaining_qty - (int) $demand->damaged_hold_qty) : $quantityReduced;
        if ($demand && $quantityReduced > $releasable + (int) $demand->fulfilled_qty) {
            throw new ApiException(__('messages.cancellation.discrepancy_unresolved'), 422, ['code' => 'discrepancy_unresolved']);
        }
        $unaccepted = $demand ? min($quantityReduced, $releasable) : $quantityReduced;
        $acceptedPart = $quantityReduced - $unaccepted;

        // R-03: a Sub-sourced line releases in its own Sub ledger (reservation shrunk, physical
        // Sub stock unchanged) — never Agent stock. Agent lines keep the existing release path.
        if ($item->isSubSourced()) {
            $this->subStockService->reduce($item, $quantityReduced, $actor, 'order_item_adjustment');
        } else {
            if ($acceptedPart > 0) {
                app(InventoryCancellationService::class)->reverseAcceptedUnits($order, $item, $acceptedPart, $actor->id);
            }
            if ($unaccepted > 0) {
                if ($item->product_variation_id) {
                    $this->stockService->releaseVariation($order->agent_id, $item->product_variation_id, $unaccepted, 'order_item_adjustment', $item->id, $actor->id);
                } else {
                    $this->stockService->releaseProduct($order->agent_id, $item->product_id, $unaccepted, 'order_item_adjustment', $item->id, $actor->id);
                }
            }
            if ($demand) {
                $demand->update([
                    'requested_qty' => $demand->requested_qty - $quantityReduced,
                    'remaining_qty' => $demand->remaining_qty - $unaccepted,
                    'fulfilled_qty' => $demand->fulfilled_qty - $acceptedPart,
                ]);
                $this->refreshStockRequestStatus($demand->stock_request_id);
            }
        }

        $totalValidPaid = (float) $order->paid_amount;
        $previousOverpaid = max(0.0, $totalValidPaid - (float) $order->total_amount);

        $item->update([
            'fulfilled_quantity' => $item->fulfilled_quantity - $quantityReduced,
            'cancelled_quantity' => $item->cancelled_quantity + $quantityReduced,
            'courier_fee_amount' => max(0.0, round((float) $item->courier_fee_amount - $releasedCourierFee, 2)),
            'status' => ($item->fulfilled_quantity - $quantityReduced) <= 0 ? 'dibatalkan' : $item->status,
        ]);

        // A re-increase followed by a second reduction can outrun the frozen unit set: the
        // snapshot never gains units (Package A/B allows reduce/increase cycles pre-dispatch).
        // When the line has no unconsumed frozen units left, do NOT invent any — fall back to
        // the legacy reversal for that movement instead of double-counting a unit.
        // F01: the legacy fallback exists ONLY for the pre-dispatch shortfall snapshot (re-increase cycles).
        // Once a handover/return/cancellation snapshot rules, an exhausted unit pool must refuse — never pay a
        // refund outside the ledger.
        if ($snapshot->provenance !== 'provisional_legacy'
            && $snapshot->trigger !== OrderFinancialSnapshotService::TRIGGER_FULFILLMENT_SHORTFALL
            && $this->snapshotService->availableUnits($snapshot, $item->id) < $quantityReduced) {
            throw new ApiException('Unit snapshot tidak mencukupi untuk pembatalan ini.', 409, ['code' => 'snapshot_units_exhausted']);
        }
        $consumption = $this->snapshotService->availableUnits($snapshot, $item->id) >= $quantityReduced
            ? $this->snapshotService->consumeUnits(
                $snapshot,
                $item->id,
                $quantityReduced,
                'fulfillment_shortfall',
                $item->id,
            )
            : null;

        $order = $this->orderTotalCalculator->recalculate($order);
        $newOverpaid = max(0.0, $totalValidPaid - (float) $order->total_amount);
        $refundAmount = round($newOverpaid - $previousOverpaid, 2);

        $adjustment = null;

        if ($refundAmount > 0.0) {
            $item->update(['refund_quantity' => $item->refund_quantity + $quantityReduced]);

            $adjustment = OrderItemAdjustment::create([
                'order_item_id' => $item->id,
                'adjusted_by' => $actor->id,
                'quantity_reduced' => $quantityReduced,
                'reason' => $reason,
                'refund_amount' => $refundAmount,
                'refund_status' => 'pending',
            ]);
        }

        if ($consumption !== null) {
            $this->recordShortfallLedger(
                $order,
                $item,
                $snapshot,
                $consumption['reduction_cents'],
                $fromQuantity,
                $fromQuantity - $quantityReduced,
                $adjustment,
                $actor,
                $reason,
            );
        }

        $this->paymentService->reconcileTotals($order);
    }

    /**
     * Canonical rule (never "quantity increased = additional payment"): an
     * increase only ever spins off a separate OrderAdditionalPayment when the
     * order was ALREADY fully paid/settled before this change (previous
     * outstanding was 0) AND the recalculated total now owes more than what
     * was already paid. A COD order (nothing collected yet) or a still-
     * partial DP simply folds the added value into the order's own
     * outstanding total/remaining_balance — never a separate ledger entry.
     */
    private function increaseFulfillment(Order $order, OrderItem $item, int $quantityAdded, User $actor, string $reason, string $additionalPaymentMethod): void
    {
        // R-03 / MAJOR-7 + MAJOR-10: an increase must not run while an unresolved fulfillment-refund
        // obligation still exists for this item. Normal read — NOT FOR UPDATE (see the reduction guard
        // below): OrderItem is already locked, and a financial-row lock here would invert against
        // Keuangan settlement and deadlock.
        $pendingRefund = OrderItemAdjustment::query()
            ->where('order_item_id', $item->id)
            ->where('refund_status', 'pending')
            ->exists();
        if ($pendingRefund) {
            throw new ApiException(__('messages.fulfillment.pending_refund_blocks_increase'), 422);
        }

        // R-03 / MAJOR-11: a second increase while the linked additional-payment obligation is still
        // pending would push Order.remaining_amount past its pending financial obligation. Normal
        // read, no FOR UPDATE (same MAJOR-10 reasoning).
        if ($item->additional_payment_id) {
            $pendingAdditional = OrderAdditionalPayment::query()->whereKey($item->additional_payment_id)->first();
            if ($pendingAdditional && $pendingAdditional->status === 'pending') {
                throw new ApiException(__('messages.fulfillment.pending_additional_payment_blocks_increase'), 422);
            }
        }

        // R-03: a fully cancelled line (reduced to zero / cancelled) is TERMINAL — do not silently
        // resurrect it, and never reactivate its Sub reservation while it remains dibatalkan.
        if ($item->status === 'dibatalkan' || $item->fulfilled_quantity <= 0) {
            throw new ApiException(__('messages.fulfillment.cannot_increase_cancelled'), 422);
        }

        // R-03 counter reconciliation: an increase first RESTORES previously cancelled units before
        // anything counts as a genuinely additional quantity. `fulfilled_quantity` is the current
        // active/billed quantity, so this keeps fulfilled/cancelled/additional mutually consistent.
        $restoreCancelled = min($quantityAdded, $item->cancelled_quantity);
        $trulyAdditional = $quantityAdded - $restoreCancelled;

        // R-03 / MAJOR-8: scale the not-yet-earned courier fee with the active fulfilled quantity,
        // from the PRE-mutation snapshot (never catalog config).
        $courierPerUnit = $item->fulfilled_quantity > 0 ? (float) $item->courier_fee_amount / $item->fulfilled_quantity : 0.0;
        $addedCourierFee = round($courierPerUnit * $quantityAdded, 2);

        // REM-001: the line's warehouse demand grows with the quantity (Order → Stock Request → inventory).
        $demand = $item->isSubSourced() ? null : $this->lockDemandForItem($order, $item);

        // R-03: a Sub-sourced increase grows the existing Sub reservation after re-checking Sub
        // sellable — never Agent stock, physical Sub stock unchanged.
        if ($item->isSubSourced()) {
            $this->subStockService->increase($item, $quantityAdded, $actor, 'order_item_adjustment');
        } elseif ($item->product_variation_id) {
            $this->stockService->reserveForVariation($order->agent_id, $item->variation, $quantityAdded, 'order_item_adjustment', $item->id, $actor->id);
        } else {
            $this->stockService->reserveForProduct($order->agent_id, $item->product, $quantityAdded, 'order_item_adjustment', $item->id, $actor->id);
        }

        if ($demand) {
            $demand->update(['requested_qty' => $demand->requested_qty + $quantityAdded, 'remaining_qty' => $demand->remaining_qty + $quantityAdded]);
            $this->refreshStockRequestStatus($demand->stock_request_id);
        }

        $totalValidPaid = (float) $order->paid_amount;
        $previousRemaining = max(0.0, (float) $order->total_amount - $totalValidPaid);

        $item->update([
            'fulfilled_quantity' => $item->fulfilled_quantity + $quantityAdded,
            'cancelled_quantity' => $item->cancelled_quantity - $restoreCancelled,
            'additional_quantity' => $item->additional_quantity + $trulyAdditional,
            'courier_fee_amount' => round((float) $item->courier_fee_amount + $addedCourierFee, 2),
        ]);

        $order = $this->orderTotalCalculator->recalculate($order);
        $this->reconcileAdditionalObligation($order, $item, $previousRemaining, $additionalPaymentMethod, $reason, $actor);
    }

    /**
     * Canonical rule (shared by a fulfillment INCREASE and a Package C added line): when the order
     * was NOT carrying an outstanding balance before the total change (previousRemaining <= 0) and
     * the new total now owes more than what has already been paid, create exactly ONE pending
     * OrderAdditionalPayment for the delta and link it to the affected line. Otherwise the added
     * value simply folds into the order's own remaining balance (unpaid / partially-paid). Never
     * marks anything paid — real money movement stays owned by PaymentService. Re-derives the
     * order's canonical remaining/payment_status via PaymentService::reconcileTotals.
     */
    public function reconcileAdditionalObligation(Order $order, OrderItem $item, float $previousRemaining, string $additionalPaymentMethod, string $reason, User $actor): void
    {
        $totalValidPaid = (float) $order->paid_amount;
        $newRemaining = max(0.0, (float) $order->total_amount - $totalValidPaid);

        if ($previousRemaining <= 0.0 && $newRemaining > 0.0) {
            $additionalAmount = round($newRemaining - $previousRemaining, 2);
            $transaction = $this->paymentService->initiateAdditionalPayment($order, $additionalPaymentMethod, $additionalAmount);

            $additionalPaymentId = OrderAdditionalPayment::create([
                'order_id' => $order->id,
                'requested_by' => $actor->id,
                'method' => $additionalPaymentMethod,
                'payment_transaction_id' => $transaction?->id,
                'amount' => $additionalAmount,
                'reason' => $reason,
                'status' => 'pending',
            ])->id;

            $item->update(['additional_payment_id' => $additionalPaymentId]);
        }

        $this->paymentService->reconcileTotals($order);
    }

    /**
     * Keuangan marks an additional payment paid — COD collected physically,
     * or a manual transfer verified off the record. Idempotent (only valid
     * while still 'pending' — a second call on an already-settled row is
     * rejected, never double-applied). Marking it PAID actually folds that
     * money into Order.paid_amount via PaymentService — this additional-
     * payment ledger is not a side channel invisible to the order's own
     * payment_summary; once paid, remaining_balance/payment_status must
     * reflect it immediately.
     */
    public function markAdditionalPaymentPaid(OrderAdditionalPayment $additionalPayment, User $actor, bool $paid): OrderAdditionalPayment
    {
        // F03: ONE lock order for every settlement entry point: proof verification → Order → transaction → obligation
        // (identical to PaymentService::verifyBankTransfer). Snapshot the linkage before taking those locks;
        // partial verification may replace it with a residual transaction, so recheck it under the obligation lock.
        $row = OrderAdditionalPayment::query()->whereKey($additionalPayment->id)->first(['order_id', 'payment_transaction_id']);
        $orderId = (int) $row->order_id;
        $txId = $row->payment_transaction_id;
        $verificationId = $txId ? \App\Models\BankTransferVerification::query()->where('payment_transaction_id', $txId)->value('id') : null;

        return DB::transaction(function () use ($additionalPayment, $actor, $paid, $orderId, $txId, $verificationId) {
            $verification = $verificationId ? \App\Models\BankTransferVerification::query()->whereKey($verificationId)->lockForUpdate()->first() : null;
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            $transaction = $txId ? \App\Models\PaymentTransaction::query()->whereKey($txId)->lockForUpdate()->first() : null;
            $additionalPayment = OrderAdditionalPayment::query()->whereKey($additionalPayment->id)->lockForUpdate()->firstOrFail();

            if ($additionalPayment->status !== 'pending'
                || (int) $additionalPayment->payment_transaction_id !== (int) $txId) {
                throw new ApiException(__('messages.payment.already_processed'), 422);
            }
            // The obligation and its transfer transaction are ONE settlement: whichever entry point gets
            // there first closes both, so the other can never apply the same money again.
            if ($transaction && $transaction->status !== \App\Enums\PaymentTransactionStatus::PENDING->value) {
                throw new ApiException(__('messages.payment.already_processed'), 422);
            }

            if ($paid && $order) {
                $this->paymentService->assertPaymentAllowed($order, (float) $additionalPayment->amount, true); // REM-004: additional ≠ regular instalment
            }

            $additionalPayment->update(['status' => $paid ? 'paid' : 'failed']);

            if ($transaction) {
                $transaction->update($paid
                    ? ['status' => \App\Enums\PaymentTransactionStatus::PAID->value, 'paid_at' => now()]
                    : ['status' => \App\Enums\PaymentTransactionStatus::FAILED->value]);
                if ($verification && $verification->status === 'pending') {
                    $verification->update($paid
                    ? ['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(), 'verified_amount' => $transaction->amount]
                        : ['status' => 'rejected', 'verified_by' => $actor->id, 'verified_at' => now(), 'rejection_reason' => 'additional payment marked failed']);
                }
            }

            if ($paid && $order) {
                $this->paymentService->applyPaymentToOrder($order, (float) $additionalPayment->amount);
                app(\App\Services\Invoice\InvoiceIssuanceService::class)->syncAfterChange($order->fresh(), 'additional_payment_settled', $actor->id); // F09
            }

            ActivityLogger::log($actor->id, $additionalPayment, 'order_additional_payment.status_changed', null, [
                'status' => $additionalPayment->status, 'actor_role' => $actor->role?->slug,
            ]);

            return $additionalPayment->fresh();
        });
    }

    /**
     * "Setiap produk dalam pesanan dapat dirubah tanggal kirimnya... sebagai
     * alternatif dari cancel order." Only while the item hasn't shipped yet
     * (still 'diterima' or 'diproses') — once 'dikirim' the delivery is
     * already underway. The new date surfaces immediately on the courier
     * dashboard's delivery queue (CourierController::index sorts by it).
     *
     * "Satu order bisa beberapa kurir karena ada kemungkinan produk yang bisa
     * di reschedule" — when this item currently shares a Shipment with
     * sibling items still on the original date, it's split off onto its own
     * fresh, unassigned Shipment (cloning the same destination/provider
     * snapshot) so its eventual courier is independent of theirs.
     *
     * $quantity lets the reschedule move only PART of the line's quantity
     * (e.g. 2 of 3 units bought) — the moved units become a brand new
     * OrderItem (own row, own fresh Shipment, own delivery date) still under
     * this same Order; the remaining units stay on the original item/date/
     * shipment untouched. Omitting $quantity (or passing the item's full
     * fulfilled_quantity) reschedules the whole line in place, exactly as
     * before — no split, no new row.
     */
    public function rescheduleItemDeliveryDate(OrderItem $item, string $newDate, User $actor, string $reason, ?int $quantity = null): OrderItem
    {
        return DB::transaction(function () use ($item, $newDate, $actor, $reason, $quantity) {
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            // FULFILLMENT WINDOW (BUSINESS-RULES §21 + this class's own contract): a reschedule is a
            // fulfillment mutation and is only ever allowed before the order starts shipping.
            //
            // This guard used to be unnecessary: an item's status mirrored its order's, so the item
            // check below was "sufficient and exact". The per-ITEM courier work unit (Human UAT:
            // courier progress is per OrderItem) broke that mirror — one item can be delivered while
            // a sibling stays in 'diproses', and CourierService::recomputeOrderStatus then moves the
            // ORDER to 'dikirim'. Without this, an Admin could reschedule (and split onto a brand-new
            // shipment) a sibling item of an order already physically in transit, while
            // adjustItemQuantity on the very same item correctly refused — same service, same order,
            // two different answers. The Order row is already locked above, so this is free.
            //
            // Owner decision (PEMENUHAN KURANG): the Admin's reschedule of a Gudang-reported SHORTAGE remainder
            // must stay possible after the first batch shipped. The exception is exactly that: the order may be
            // `dikirim` only when THIS line is itself still undispatched (item guard below) AND carries an open
            // shortage report with outstanding quantity. Every other line keeps the original window.
            $orderWindowClosed = in_array($order->status, ['dikirim', 'terkirim', 'pengembalian', 'kembali', 'dibatalkan'], true);
            $shortageRemainder = $order->status === 'dikirim'
                && app(FulfillmentShortageService::class)->lineHasOpenShortage($item->id);
            if ($orderWindowClosed && ! $shortageRemainder) {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
            }

            if (! in_array($item->status, ['diterima', 'diproses'], true)) {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
            }

            // LOCKED BUSINESS RULE (Human, final 2026-10-07): a requested delivery date is
            // re-datable ONLY for (1) KURIR ONLINE and (2) SELF DELIVERY / Sub. Ekspedisi/RajaOngkir
            // and Pickup/Ambil di Tempat are FORBIDDEN — their schedule belongs to their own
            // workflow, and a Pickup order must never become a courier-online shipment. Enforced
            // HERE, under the canonical Order lock, so a direct/API call is refused exactly like the
            // hidden UI control (frontend gating is convenience only). Classification comes from the
            // canonical persisted fields via Shipment::isDeliveryDateReschedulable() — never inferred
            // from whether a date happens to be set — and it is an allowlist, so an unrecognised
            // provider stays non-reschedulable.
            $shipment = $this->shipmentForDeliveryMethod($item);
            if (! $shipment?->isDeliveryDateReschedulable()) {
                throw new ApiException(__('messages.fulfillment.reschedule_courier_online_only', [
                    'method' => $shipment?->deliveryMethodLabel() ?? '—',
                ]), 422);
            }

            if ($quantity !== null) {
                if ($quantity < 1 || $quantity > $item->fulfilled_quantity) {
                    throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
                }

                if ($quantity < $item->fulfilled_quantity) {
                    return $this->splitItemForReschedule($item, $quantity, $newDate, $actor, $reason);
                }
            }

            $this->splitShipmentIfShared($item, $actor);

            $previousDate = $item->requested_delivery_date?->toDateString();
            $item->update(['requested_delivery_date' => $newDate]);
            $item->refresh();

            // The new date decides which shipment the item now belongs to. Grouping is
            // shipment-truth (see consolidateIntoShipmentForDate): a moved item JOINS an
            // existing, still-lifecycle-eligible shipment for that date, otherwise its own
            // shipment becomes the canonical shipment for the new date.
            $this->consolidateIntoShipmentForDate($item, $newDate, $actor);

            ActivityLogger::log($actor->id, $item, 'order_item.delivery_rescheduled', $reason, [
                'order_id' => $item->order_id, 'from' => $previousDate, 'to' => $newDate, 'actor_role' => $actor->role?->slug,
                'delivery_method' => $item->shipment?->deliveryMethodLabel() ?? $this->shipmentForDeliveryMethod($item)?->deliveryMethodLabel(),
            ]);

            return $item->fresh();
        });
    }

    /**
     * REM-003 — Kurir Toko subsequent delivery batches. The Koordinator Kurir carves part of a line
     * that has NOT been dispatched yet into its own later batch (own OrderItem + fresh pending Shipment,
     * same delivery date), even after other batches of the order already shipped. It reuses the
     * canonical item split: the unaccepted warehouse demand moves with the carved units (so
     * requested/fulfilled/remaining stay conserved and nothing is reserved twice), price/fee are
     * apportioned, and accepted units stay on the original line, ready to assign and ship.
     * Refused for delivered/dispatched lines, assigned shipments, Sub (SKS), expedition and pickup.
     */
    public function splitItemIntoBatch(OrderItem $item, int $quantity, User $actor, ?string $note = null): OrderItem
    {
        if (! $actor->isRole('koordinator-kurir')) {
            throw new ApiException(__('messages.courier.coordinator_only'), 403);
        }

        return DB::transaction(function () use ($item, $quantity, $actor, $note) {
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ((int) $order->agent_id !== (int) $actor->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 403);
            }
            if (! in_array($order->status, ['diproses', 'dikirim'], true) || $item->status !== 'diproses') {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
            }
            if ($item->isSubSourced()) {
                throw new ApiException(__('messages.order.line_addition_sub_not_supported'), 422);
            }
            $shipment = $item->shipment_id ? Shipment::query()->whereKey($item->shipment_id)->lockForUpdate()->first() : null;
            if (! $shipment || $shipment->status !== 'pending' || $shipment->courier_id !== null
                || $shipment->delivery_mode !== Shipment::DELIVERY_MODE_STANDARD
                || in_array($shipment->shipping_provider_code, [Shipment::PROVIDER_EXPEDITION, Shipment::PROVIDER_PICKUP], true)) {
                throw new ApiException(__('messages.courier.provider_not_assignable'), 422);
            }
            if ($quantity < 1 || $quantity >= $item->fulfilled_quantity) {
                throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
            }

            return $this->splitItemForReschedule($item, $quantity, $item->requested_delivery_date?->toDateString(), $actor, $note ?? 'batch lanjutan', false);
        });
    }

    /**
     * The shipment that decides this item's delivery method: the item's own shipment, otherwise
     * the order's first one — a split/rescheduled item can sit on a shipment created after the
     * original. Returns null when the order has no shipment at all, which is treated as
     * non-reschedulable (allowlist).
     */
    private function shipmentForDeliveryMethod(OrderItem $item): ?Shipment
    {
        return $item->shipment
            ?? Shipment::query()->where('order_id', $item->order_id)->orderBy('id')->first();
    }

    /**
     * Generic in-transaction regroup (algorithm G): re-homes the just-re-dated item onto the
     * correct shipment for $newDate AND canonicalizes the affected grouping keys, so every
     * successful date change leaves the domain canonical without any manual reconcile.
     *
     * Runs inside rescheduleItemDeliveryDate's transaction, which already holds the canonical
     * Order lock first — two concurrent date changes therefore serialize and the loser observes
     * the winner's committed grouping rather than racing it.
     *
     * Rules (LOCKED):
     *  - candidates are ALWAYS restricted to the SAME order (`order_id = $item->order_id`), so a
     *    date change can never move an item into another order's shipment;
     *  - compatibility follows the item's own delivery semantics: a self_sub item only ever joins
     *    a self_sub shipment of the SAME owner; a standard item only a standard shipment. Modes
     *    and owners never merge;
     *  - only a shipment whose lifecycle still permits taking new items qualifies
     *    (`ShipmentCanonicalizationService::mergeBlocker`). An already dispatched/delivered/
     *    assigned shipment is never joined — existing courier-assignment rules stay authoritative;
     *  - when several eligible shipments already carry the new date, the whole compatible group is
     *    canonicalized inside this transaction (oldest wins) instead of leaving a second Y unit;
     *  - otherwise the item keeps its own shipment, which now represents the new date (created by
     *    splitShipmentIfShared when it had to leave a shared one), and an emptied source shipment
     *    is removed only when the existing lifecycle semantics allow it.
     *
     * SHARED by every path that can put a NEW item on a shipment — the whole-line reschedule, the
     * partial-quantity reschedule split and the SC-03 added line — so the LOCKED grouping rule is
     * enforced by ONE implementation instead of being re-derived (and forgotten) per caller.
     * Public for that reason; the canonical Order lock is the caller's responsibility and is
     * already held by all three callers.
     *
     * @param  string  $context  audit description naming which flow triggered the regroup.
     */
    public function consolidateIntoShipmentForDate(OrderItem $item, string $newDate, User $actor, string $context = 'reschedule'): void
    {
        $currentShipmentId = $item->shipment_id;

        if (! $currentShipmentId) {
            return;
        }

        $canonicalizer = new ShipmentCanonicalizationService();

        $current = Shipment::query()->whereKey($currentShipmentId)->lockForUpdate()->first();

        if (! $current || $current->order_id !== $item->order_id) {
            return;
        }

        // Compatibility follows the item's own delivery semantics, never the calendar alone.
        $isSelfSub = $current->delivery_mode === Shipment::DELIVERY_MODE_SELF_SUB;
        $ownerId = $isSelfSub ? $current->self_delivered_by_user_id : null;

        $candidates = Shipment::query()
            ->where('order_id', $item->order_id)
            ->whereKeyNot($currentShipmentId)
            ->where('delivery_mode', $current->delivery_mode)
            ->when($isSelfSub, fn ($q) => $q->where('self_delivered_by_user_id', $ownerId))
            ->whereHas('orderItems', fn ($q) => $q->whereDate('requested_delivery_date', $newDate))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $joinedTarget = null;

        foreach ($candidates as $candidate) {
            if ($canonicalizer->mergeBlocker($candidate) !== null) {
                continue;
            }

            $joinedTarget = $joinedTarget ?? $candidate;
        }

        if ($joinedTarget) {
            // Join the oldest eligible Y shipment, then fold every OTHER still-eligible Y shipment
            // into the oldest one as well — one date change must never leave two canonical units.
            $group = $candidates
                ->filter(fn (Shipment $s) => $canonicalizer->mergeBlocker($s) === null)
                ->values();
            $canonical = $group->sortBy('id')->firstOrFail();
            $canonicalizer->mergeGroupInto($group->sortBy('id')->values(), $canonical);

            $item->update(['shipment_id' => $canonical->id]);
            $joinedTarget = $canonical;

            ActivityLogger::log($actor->id, $joinedTarget, 'shipment.reused_for_reschedule', $context, [
                'order_id' => $joinedTarget->order_id, 'order_item_id' => $item->id,
                'requested_delivery_date' => $newDate, 'released_shipment_id' => $currentShipmentId,
            ]);
        }

        // The shipment the item just left must not linger as an empty card in Dispatch /
        // the receipt grouping. Only ever removed when it is genuinely empty, still
        // unassigned and undelivered; anything with an executor, a status or items stays.
        $orphan = Shipment::query()->whereKey($currentShipmentId)->lockForUpdate()->first();
        if ($orphan
            && $orphan->courier_id === null
            && $orphan->self_delivered_by_user_id === null
            && $orphan->status === 'pending'
            && ! OrderItem::query()->where('shipment_id', $orphan->id)->exists()) {
            $orphan->delete();
        }
    }

    /**
     * Carves $quantityMoved units off $item into a brand new OrderItem on its
     * own fresh Shipment and the new date; the original row keeps the rest on
     * its original date/shipment. subtotal_snapshot and original_quantity are
     * split in proportion so the two rows still sum to the pre-split totals
     * (unit_price_snapshot itself never changes — it's already per unit).
     * agent_fee_amount/sales_fee_amount stay on the original row untouched and are zeroed on the new
     * one: these were already computed for the item's full original_quantity and recorded once in
     * `commissions` against the original item id at order creation — duplicating them would make it
     * look like the split doubled the agent/sales fee.
     *
     * courier_fee_amount is different (R-03 / MAJOR-8): the courier fee is earned ON DELIVERY, PER
     * ITEM, so the moved units' share must follow them onto the child. It is allocated proportionally
     * from the PRE-split snapshot (per-unit = pre fee / pre active qty), with the rounding remainder
     * kept on the parent, so parent + child exactly equals the pre-split courier fee.
     */
    private function splitItemForReschedule(OrderItem $item, int $quantityMoved, ?string $newDate, User $actor, string $reason, bool $consolidate = true): OrderItem
    {
        $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
        $remainingQuantity = $item->fulfilled_quantity - $quantityMoved;
        $movedSubtotal = round((float) $item->unit_price_snapshot * $quantityMoved, 2);

        // R-03 / MAJOR-8: proportional courier-fee allocation from the pre-split snapshot.
        $courierPerUnit = $item->fulfilled_quantity > 0 ? (float) $item->courier_fee_amount / $item->fulfilled_quantity : 0.0;
        $movedCourierFee = round($courierPerUnit * $quantityMoved, 2);
        $retainedCourierFee = round((float) $item->courier_fee_amount - $movedCourierFee, 2);

        // R-03 / decision G: validate + lock the order-generated StockRequest line BEFORE the split
        // child exists, so a request whose unfulfilled remainder cannot cover the moved quantity is
        // rejected (422) without leaving inconsistent StockRequestItem state. Sub orders carry no
        // Agent stock request, so this is a no-op for them.
        $requestItem = $item->isSubSourced() ? null : $this->lockStockRequestItemForSplit($item, $quantityMoved);

        $newItem = OrderItem::create([
            'order_id' => $item->order_id,
            'split_from_order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variation_id' => $item->product_variation_id,
            'stock_source' => $item->stock_source,
            'sub_location_id' => $item->sub_location_id,
            'product_name_snapshot' => $item->product_name_snapshot,
            'variation_label_snapshot' => $item->variation_label_snapshot,
            'sku_snapshot' => $item->sku_snapshot,
            'unit_price_snapshot' => $item->unit_price_snapshot,
            'agent_fee_amount' => 0,
            'sales_fee_amount' => 0,
            'courier_fee_amount' => $movedCourierFee,
            'subtotal_snapshot' => $movedSubtotal,
            'original_quantity' => $quantityMoved,
            'fulfilled_quantity' => $quantityMoved,
            'requested_delivery_date' => $newDate,
            'status' => $item->status,
        ]);

        if ($requestItem) {
            $this->applyStockRequestSplit($requestItem, $newItem, $quantityMoved);
        }

        $this->assignFreshShipment($newItem, $order, $actor);

        // The child now has its own shipment, so it gets the same LOCKED canonical grouping the
        // whole-line reschedule applies: if another shipment of this order already IS the canonical
        // unit for the target date, the child joins it instead of becoming a duplicate.
        if ($consolidate) {
            $this->consolidateIntoShipmentForDate($newItem, $newDate, $actor, 'reschedule_split');
        }

        // R-03 / decision E: Sub inventory reconciliation — the source reservation is reduced by the
        // moved quantity, then the child receives its own ACTIVE reservation for it. Physical Sub
        // stock is unchanged and the two reservations still sum to the pre-split total.
        if ($item->isSubSourced()) {
            $this->subStockService->reduce($item, $quantityMoved, $actor, 'order_item_split');
            $this->subStockService->reserve($newItem, $item->sub_location_id, $order->agent_id, $quantityMoved, $actor);
        }

        $item->update([
            'original_quantity' => $item->original_quantity - $quantityMoved,
            'fulfilled_quantity' => $remainingQuantity,
            'subtotal_snapshot' => (float) $item->subtotal_snapshot - $movedSubtotal,
            'courier_fee_amount' => $retainedCourierFee,
        ]);

        ActivityLogger::log($actor->id, $newItem, 'order_item.split_for_reschedule', $reason, [
            'order_id' => $item->order_id, 'from_order_item_id' => $item->id,
            'quantity_moved' => $quantityMoved, 'new_delivery_date' => $newDate, 'actor_role' => $actor->role?->slug,
        ]);

        return $newItem->fresh();
    }

    /**
     * R-03 / decision G: lock the StockRequestItem backing this OrderItem and reject the split when
     * its unfulfilled remainder cannot cover the moved quantity. Moved units are always carved from
     * the REMAINING quantity, so already-fulfilled warehouse history is never rewritten.
     */
    private function lockStockRequestItemForSplit(OrderItem $item, int $quantityMoved): ?StockRequestItem
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $item->order_id)->lockForUpdate()->first();

        if (! $request || $request->status === 'cancelled') {
            return null;
        }

        $requestItem = StockRequestItem::query()
            ->where('stock_request_id', $request->id)
            ->where('order_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if (! $requestItem) {
            return null;
        }

        if ($requestItem->remaining_qty < $quantityMoved) {
            throw new ApiException(__('messages.fulfillment.split_stock_request_locked'), 422);
        }

        return $requestItem;
    }

    /** Move the request demand onto the split child, conserving requested/fulfilled/remaining totals. */
    private function applyStockRequestSplit(StockRequestItem $requestItem, OrderItem $newItem, int $quantityMoved): void
    {
        $requestItem->update([
            'requested_qty' => $requestItem->requested_qty - $quantityMoved,
            'remaining_qty' => $requestItem->remaining_qty - $quantityMoved,
        ]);

        StockRequestItem::create([
            'stock_request_id' => $requestItem->stock_request_id,
            'order_item_id' => $newItem->id,
            'product_id' => $newItem->product_id,
            'product_variation_id' => $newItem->product_variation_id,
            'sku_snapshot' => $newItem->sku_snapshot,
            'requested_qty' => $quantityMoved,
            'fulfilled_qty' => 0,
            'remaining_qty' => $quantityMoved,
        ]);
    }

    /** Always gives $item its own brand new pending Shipment, cloning the order's destination/provider snapshot — used for a newly split-off item, which never shares a shipment with anything yet. */
    public function assignFreshShipment(OrderItem $item, Order $order, User $actor, string $event = 'shipment.split_for_reschedule'): void
    {
        $reference = Shipment::query()->where('order_id', $order->id)->latest('id')->first();

        // R-03: a Sub-sourced child stays on the self-delivery path — self_sub + the owning
        // Sales-Kurir-Sub actor, never a Kurir (courier_id stays NULL).
        $isSub = $item->isSubSourced();
        $selfDeliveredBy = null;
        if ($isSub) {
            $selfDeliveredBy = $reference?->self_delivered_by_user_id
                ?? ($item->sub_location_id
                    ? WarehouseSubLocation::withoutGlobalScopes()->whereKey($item->sub_location_id)->value('owner_user_id')
                    : null);
        }

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_provider_id' => $reference?->shipping_provider_id,
            'shipping_provider_code' => $reference?->shipping_provider_code,
            'origin_latitude' => $reference?->origin_latitude,
            'origin_longitude' => $reference?->origin_longitude,
            'destination_latitude' => $reference?->destination_latitude,
            'destination_longitude' => $reference?->destination_longitude,
            'distance_km' => $reference?->distance_km,
            'provider_meta' => $reference?->provider_meta,
            'status' => 'pending',
            'delivery_mode' => $isSub ? Shipment::DELIVERY_MODE_SELF_SUB : Shipment::DELIVERY_MODE_STANDARD,
            'self_delivered_by_user_id' => $selfDeliveredBy,
        ]);

        $item->update(['shipment_id' => $shipment->id]);

        ActivityLogger::log($actor->id, $shipment, $event, null, [
            'order_id' => $order->id, 'order_item_id' => $item->id,
        ]);
    }

    private function splitShipmentIfShared(OrderItem $item, User $actor): void
    {
        if (! $item->shipment_id) {
            return;
        }

        $stillShared = OrderItem::query()
            ->where('shipment_id', $item->shipment_id)
            ->whereKeyNot($item->id)
            ->exists();

        if (! $stillShared) {
            return;
        }

        $original = Shipment::query()->whereKey($item->shipment_id)->lockForUpdate()->firstOrFail();

        $split = Shipment::create([
            'order_id' => $original->order_id,
            'shipping_provider_id' => $original->shipping_provider_id,
            'shipping_provider_code' => $original->shipping_provider_code,
            'origin_latitude' => $original->origin_latitude,
            'origin_longitude' => $original->origin_longitude,
            'destination_latitude' => $original->destination_latitude,
            'destination_longitude' => $original->destination_longitude,
            'distance_km' => $original->distance_km,
            'provider_meta' => $original->provider_meta,
            'status' => 'pending',
            // R-03: preserve the delivery path (self_sub + owning actor) across the shipment split.
            'delivery_mode' => $original->delivery_mode,
            'self_delivered_by_user_id' => $original->self_delivered_by_user_id,
        ]);

        $item->update(['shipment_id' => $split->id]);

        ActivityLogger::log($actor->id, $split, 'shipment.split_for_reschedule', null, [
            'order_id' => $original->order_id, 'from_shipment_id' => $original->id, 'order_item_id' => $item->id,
        ]);
    }

    /**
     * Record the shortfall in the 360° ledger: the frozen units were already consumed by the
     * caller, so this only adds the sales reduction and the resulting refund entitlement.
     * applied_to_order_total=true because recalculate() has ALREADY lowered total_amount —
     * marking it false would double-subtract the reduction in RefundMetrics::billable.
     */
    private function recordShortfallLedger(
        Order $order,
        OrderItem $item,
        OrderFinancialSnapshot $snapshot,
        int $reductionCents,
        int $fromQuantity,
        int $toQuantity,
        ?OrderItemAdjustment $adjustment,
        User $actor,
        string $reason,
    ): void {
        if ($reductionCents <= 0) {
            return;
        }

        OrderSalesReduction::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $item->id,
            'amount' => Money::amount($reductionCents),
            'applied_to_order_total' => true,
            'provenance' => $snapshot->provenance === 'provisional_legacy' ? 'provisional_legacy' : 'forward',
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);

        $key = $adjustment
            ? 'adjustment:'.$adjustment->id
            : 'shortfall:'.$item->id.':'.$fromQuantity.'-'.$toQuantity;

        $attrs = [
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $item->id,
            'obligation_key' => $key,
            'snapshot_id' => $snapshot->id,
            'reason' => $reason,
            'deferred_reason' => 'Shortfall exceeds available cash',
            // The approving reduction itself is the owner/admin sign-off for this entitlement;
            // Keuangan only executes the payout.
            'approval_source' => 'fulfillment_reduction',
        ];

        if ($snapshot->provenance === 'provisional_legacy') {
            $this->refundLedger->createVerificationObligation(
                $order,
                $attrs,
                $reductionCents,
                $actor,
                'provisional_legacy',
            );

            return;
        }

        $this->refundLedger->createForwardObligation(
            $order,
            $attrs,
            $reductionCents,
            $actor,
            autoApprove: true,
        );
    }

    /**
     * Keuangan marks a fulfillment-shortfall refund as actually processed
     * (money sent back). Idempotent (only valid while still 'pending').
     * Once 'processed', the refunded amount actually leaves Order.paid_amount
     * (via PaymentService::reverseAppliedPayment) — a processed refund is
     * real money returned, not just a status label; overpaid_amount must
     * shrink to reflect it.
     */
    public function markAdjustmentRefundStatus(OrderItemAdjustment $adjustment, User $actor, string $status): OrderItemAdjustment
    {
        // Resolve the owning ORDER id first so the canonical Order lock can be
        // taken FIRST. This reads only the lineage foreign key — never payment
        // state — and it is immutable for an existing adjustment row.
        $orderId = OrderItem::query()->whereKey($adjustment->order_item_id)->value('order_id');

        return DB::transaction(function () use ($adjustment, $actor, $status, $orderId) {
            // Canonical Order-first lock order (AGENTS.md §11), matching
            // adjustItemQuantity/applyFulfillment/reschedule in this same service.
            //
            // Reversing real money must re-read the CURRENT paid_amount under
            // that lock. Locking only the adjustment row is NOT enough: two
            // DIFFERENT refund rows of the same order are guarded by different
            // locks, so both processors read one stale Order snapshot and the
            // second write silently loses a money movement — Order.paid_amount
            // then disagrees with the processed refund rows, i.e. a second
            // payment truth. Regression: FinancialConcurrencyTest::
            // test_distinct_refunds_on_same_order_preserve_both_money_movements.
            $order = $orderId
                ? Order::query()->whereKey($orderId)->lockForUpdate()->first()
                : null;

            $adjustment = OrderItemAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($adjustment->refund_status !== 'pending') {
                throw new ApiException(__('messages.payment.already_processed'), 422);
            }

            $adjustment->update(['refund_status' => $status]);

            if ($status === 'processed' && $order) {
                // Once the order has a financial snapshot, the refund is settled through the
                // ledger so Order.paid_amount keeps exactly ONE writer. A legacy adjustment with
                // no ledger obligation (pre-snapshot history) keeps the old reversal path.
                $obligation = RefundObligation::query()
                    ->where('obligation_key', 'adjustment:'.$adjustment->id)
                    ->lockForUpdate()
                    ->first();

                $settled = $obligation
                    ? $this->refundLedger->settleLegacyConfirmed($obligation, $actor)
                    : null;

                if (! $settled) {
                    $this->paymentService->reverseAppliedPayment($order, (float) $adjustment->refund_amount);
                }
            }

            ActivityLogger::log($actor->id, $adjustment, 'order_item_adjustment.refund_status_changed', null, [
                'refund_status' => $status, 'actor_role' => $actor->role?->slug,
            ]);

            return $adjustment->fresh();
        });
    }
}
