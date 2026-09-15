<?php

namespace App\Services\Payment;

use App\Models\Order;

/**
 * Canonical payment-summary shape — the single formula for "how much has
 * this order actually collected", reused by OrderResource, the item-level
 * transaction report, and Google Sheets so none of them ever diverge.
 *
 * grand_total/total_paid/remaining_balance/payment_status are read straight
 * off Order — they are already authoritative (see PaymentService::
 * recalculatePaymentStatus, the single place that writes them). Only
 * verified_dp is derived here: Order has no separate "how much of the DP
 * specifically has cleared" column, so it's computed as
 * min(requested_dp, total_paid) — capped at the requested DP amount even
 * after a settlement pushes total_paid past it (Blueprint: history of the
 * DP tranche itself never changes once verified).
 */
class PaymentSummaryService
{
    /**
     * @return array{grand_total:float,requested_dp:float,verified_dp:float,total_paid:float,remaining_balance:float,payment_status:string,is_fully_paid:bool}
     */
    public static function summarize(Order $order): array
    {
        $requestedDp = (float) $order->dp_amount;
        $totalPaid = (float) $order->paid_amount;

        return [
            'grand_total' => (float) $order->total_amount,
            'requested_dp' => $requestedDp,
            'verified_dp' => $requestedDp > 0 ? min($requestedDp, $totalPaid) : 0.0,
            'total_paid' => $totalPaid,
            'remaining_balance' => (float) $order->remaining_amount,
            'payment_status' => $order->payment_status,
            'is_fully_paid' => $order->isFullyPaid(),
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
