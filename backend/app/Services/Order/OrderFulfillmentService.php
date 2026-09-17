<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Payment\PaymentService;
use App\Services\Stock\StockService;
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
        private readonly PaymentService $paymentService,
        private readonly OrderTotalCalculator $orderTotalCalculator,
    ) {}

    public function adjustItemQuantity(OrderItem $item, int $newFulfilledQuantity, User $actor, string $reason, string $additionalPaymentMethod = 'transfer'): OrderItem
    {
        if ($newFulfilledQuantity < 0) {
            throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
        }

        return DB::transaction(function () use ($item, $newFulfilledQuantity, $actor, $reason, $additionalPaymentMethod) {
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();

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
        if ($item->product_variation_id) {
            $this->stockService->releaseVariation($order->agent_id, $item->product_variation_id, $quantityReduced, 'order_item_adjustment', $item->id, $actor->id);
        } else {
            $this->stockService->releaseProduct($order->agent_id, $item->product_id, $quantityReduced, 'order_item_adjustment', $item->id, $actor->id);
        }

        $totalValidPaid = (float) $order->paid_amount;
        $previousOverpaid = max(0.0, $totalValidPaid - (float) $order->total_amount);

        $item->update([
            'fulfilled_quantity' => $item->fulfilled_quantity - $quantityReduced,
            'cancelled_quantity' => $item->cancelled_quantity + $quantityReduced,
            'status' => ($item->fulfilled_quantity - $quantityReduced) <= 0 ? 'dibatalkan' : $item->status,
        ]);

        $order = $this->orderTotalCalculator->recalculate($order);
        $newOverpaid = max(0.0, $totalValidPaid - (float) $order->total_amount);
        $refundAmount = round($newOverpaid - $previousOverpaid, 2);

        if ($refundAmount > 0.0) {
            $item->update(['refund_quantity' => $item->refund_quantity + $quantityReduced]);

            OrderItemAdjustment::create([
                'order_item_id' => $item->id,
                'adjusted_by' => $actor->id,
                'quantity_reduced' => $quantityReduced,
                'reason' => $reason,
                'refund_amount' => $refundAmount,
                'refund_status' => 'pending',
            ]);
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
        if ($item->product_variation_id) {
            $this->stockService->reserveForVariation($order->agent_id, $item->variation, $quantityAdded, 'order_item_adjustment', $item->id, $actor->id);
        } else {
            $this->stockService->reserveForProduct($order->agent_id, $item->product, $quantityAdded, 'order_item_adjustment', $item->id, $actor->id);
        }

        $totalValidPaid = (float) $order->paid_amount;
        $previousRemaining = max(0.0, (float) $order->total_amount - $totalValidPaid);

        $item->update([
            'fulfilled_quantity' => $item->fulfilled_quantity + $quantityAdded,
            'additional_quantity' => $item->additional_quantity + $quantityAdded,
        ]);

        $order = $this->orderTotalCalculator->recalculate($order);
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
        return DB::transaction(function () use ($additionalPayment, $actor, $paid) {
            $additionalPayment = OrderAdditionalPayment::query()->whereKey($additionalPayment->id)->lockForUpdate()->firstOrFail();

            if ($additionalPayment->status !== 'pending') {
                throw new ApiException(__('messages.payment.already_processed'), 422);
            }

            $additionalPayment->update(['status' => $paid ? 'paid' : 'failed']);

            if ($paid && ($order = $additionalPayment->order)) {
                $this->paymentService->applyPaymentToOrder($order, (float) $additionalPayment->amount);
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
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! in_array($item->status, ['diterima', 'diproses'], true)) {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
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

            ActivityLogger::log($actor->id, $item, 'order_item.delivery_rescheduled', $reason, [
                'order_id' => $item->order_id, 'from' => $previousDate, 'to' => $newDate, 'actor_role' => $actor->role?->slug,
            ]);

            return $item->fresh();
        });
    }

    /**
     * Carves $quantityMoved units off $item into a brand new OrderItem on its
     * own fresh Shipment and the new date; the original row keeps the rest on
     * its original date/shipment. subtotal_snapshot and original_quantity are
     * split in proportion so the two rows still sum to the pre-split totals
     * (unit_price_snapshot itself never changes — it's already per unit).
     * agent_fee_amount/sales_fee_amount/courier_fee_amount stay on the
     * original row untouched and are zeroed on the new one: these were
     * already computed for the item's full original_quantity and recorded
     * once in `commissions` against the original item id at order creation —
     * duplicating them onto the new row would make it look like the split
     * doubled the agent/sales/courier fee.
     */
    private function splitItemForReschedule(OrderItem $item, int $quantityMoved, string $newDate, User $actor, string $reason): OrderItem
    {
        $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
        $remainingQuantity = $item->fulfilled_quantity - $quantityMoved;
        $movedSubtotal = round((float) $item->unit_price_snapshot * $quantityMoved, 2);

        $newItem = OrderItem::create([
            'order_id' => $item->order_id,
            'product_id' => $item->product_id,
            'product_variation_id' => $item->product_variation_id,
            'product_name_snapshot' => $item->product_name_snapshot,
            'variation_label_snapshot' => $item->variation_label_snapshot,
            'sku_snapshot' => $item->sku_snapshot,
            'unit_price_snapshot' => $item->unit_price_snapshot,
            'agent_fee_amount' => 0,
            'sales_fee_amount' => 0,
            'courier_fee_amount' => 0,
            'subtotal_snapshot' => $movedSubtotal,
            'original_quantity' => $quantityMoved,
            'fulfilled_quantity' => $quantityMoved,
            'requested_delivery_date' => $newDate,
            'status' => $item->status,
        ]);

        $this->assignFreshShipment($newItem, $order, $actor);

        $item->update([
            'original_quantity' => $item->original_quantity - $quantityMoved,
            'fulfilled_quantity' => $remainingQuantity,
            'subtotal_snapshot' => (float) $item->subtotal_snapshot - $movedSubtotal,
        ]);

        ActivityLogger::log($actor->id, $newItem, 'order_item.split_for_reschedule', $reason, [
            'order_id' => $item->order_id, 'from_order_item_id' => $item->id,
            'quantity_moved' => $quantityMoved, 'new_delivery_date' => $newDate, 'actor_role' => $actor->role?->slug,
        ]);

        return $newItem->fresh();
    }

    /** Always gives $item its own brand new pending Shipment, cloning the order's destination/provider snapshot — used for a newly split-off item, which never shares a shipment with anything yet. */
    private function assignFreshShipment(OrderItem $item, Order $order, User $actor): void
    {
        $reference = Shipment::query()->where('order_id', $order->id)->latest('id')->first();

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
        ]);

        $item->update(['shipment_id' => $shipment->id]);

        ActivityLogger::log($actor->id, $shipment, 'shipment.split_for_reschedule', null, [
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
        ]);

        $item->update(['shipment_id' => $split->id]);

        ActivityLogger::log($actor->id, $split, 'shipment.split_for_reschedule', null, [
            'order_id' => $original->order_id, 'from_shipment_id' => $original->id, 'order_item_id' => $item->id,
        ]);
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
        return DB::transaction(function () use ($adjustment, $actor, $status) {
            $adjustment = OrderItemAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($adjustment->refund_status !== 'pending') {
                throw new ApiException(__('messages.payment.already_processed'), 422);
            }

            $order = $adjustment->orderItem?->order;
            $adjustment->update(['refund_status' => $status]);

            if ($status === 'processed' && $order) {
                $this->paymentService->reverseAppliedPayment($order, (float) $adjustment->refund_amount);
            }

            ActivityLogger::log($actor->id, $adjustment, 'order_item_adjustment.refund_status_changed', null, [
                'refund_status' => $status, 'actor_role' => $actor->role?->slug,
            ]);

            return $adjustment->fresh();
        });
    }
}
