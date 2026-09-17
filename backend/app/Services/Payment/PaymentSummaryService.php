<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;

/**
 * Canonical payment-summary shape — the single formula for "how much has
 * this order actually collected", reused by OrderResource, the item-level
 * transaction report, and Google Sheets so none of them ever diverge.
 *
 * grand_total/total_paid/remaining_balance/payment_status are read straight
 * off Order — they are already authoritative (see PaymentService::
 * recalculatePaymentStatus, the single place that writes them, and
 * OrderTotalCalculator, the single place total_amount is recomputed after
 * item changes). verified_dp is derived: Order has no separate "how much of
 * the DP specifically has cleared" column, so it's computed as
 * min(requested_dp, total_paid) — capped at the requested DP amount even
 * after a settlement pushes total_paid past it (the DP tranche's own history
 * never changes once verified).
 *
 * overpaid_amount = max(0, total_paid - grand_total) — the canonical refund-
 * eligibility signal (see OrderFulfillmentService::reduceFulfillment, which
 * uses the identical formula to decide whether a reduction actually earns a
 * refund). The additional_payment_ and refund_ fields summarize the
 * OUTSTANDING (pending) portion of each ledger — once a row is actually
 * paid/processed it folds
 * back into total_paid/paid_amount via PaymentService and stops being
 * "pending" here (see OrderFulfillmentService::markAdditionalPaymentPaid/
 * markAdjustmentRefundStatus).
 */
class PaymentSummaryService
{
    /**
     * @return array{grand_total:float,requested_dp:float,verified_dp:float,total_paid:float,remaining_balance:float,overpaid_amount:float,payment_status:string,is_fully_paid:bool,additional_payment_amount:float,additional_payment_status:?string,refund_amount:float,refund_status:?string}
     */
    public static function summarize(Order $order): array
    {
        $requestedDp = (float) $order->dp_amount;
        $totalPaid = (float) $order->paid_amount;
        $grandTotal = (float) $order->total_amount;

        $additionalPayments = OrderAdditionalPayment::query()->where('order_id', $order->id)->get();
        $pendingAdditional = (float) $additionalPayments->where('status', 'pending')->sum('amount');
        $latestAdditional = $additionalPayments->sortByDesc('id')->first();

        $itemIds = OrderItem::query()->where('order_id', $order->id)->pluck('id');
        $adjustments = OrderItemAdjustment::query()->whereIn('order_item_id', $itemIds)->get();
        $pendingRefund = (float) $adjustments->where('refund_status', 'pending')->sum('refund_amount');
        $latestAdjustment = $adjustments->sortByDesc('id')->first();

        return [
            'grand_total' => $grandTotal,
            'requested_dp' => $requestedDp,
            'verified_dp' => $requestedDp > 0 ? min($requestedDp, $totalPaid) : 0.0,
            'total_paid' => $totalPaid,
            'remaining_balance' => (float) $order->remaining_amount,
            'overpaid_amount' => max(0.0, $totalPaid - $grandTotal),
            'payment_status' => $order->payment_status,
            'is_fully_paid' => $order->isFullyPaid(),
            'additional_payment_amount' => $pendingAdditional,
            'additional_payment_status' => $pendingAdditional > 0 ? 'pending' : $latestAdditional?->status,
            'refund_amount' => $pendingRefund,
            'refund_status' => $pendingRefund > 0 ? 'pending' : $latestAdjustment?->refund_status,
        ];
    }

    /**
     * The same verified_dp formula as an SQL expression, for bulk report
     * queries where pulling every Order model into PHP just to call
     * summarize() row-by-row would be an N+1-shaped waste. $dpColumn/
     * $paidColumn must already be qualified (e.g. 'o.dp_amount').
     */
    public static function verifiedDpSql(string $dpColumn, string $paidColumn): string
    {
        return "CASE WHEN $dpColumn > 0 THEN LEAST($dpColumn, $paidColumn) ELSE 0 END";
    }
}
