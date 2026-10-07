<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\PaymentTransaction;

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
 *
 * UAT-004: submitted-but-unverified money is exposed separately as
 * submitted_dp / pending_verification_status / has_pending_proof — a
 * submitted DP ("DP Diajukan") NEVER increases total_paid/verified_dp
 * before verifyBankTransfer() approves it (PaymentService is the sole
 * writer of paid_amount). Finance triage reads these fields; accounting
 * reads total_paid/verified_dp.
 */
class PaymentSummaryService
{
    /**
     * @return array{grand_total:float,requested_dp:float,verified_dp:float,submitted_dp:float,total_paid:float,remaining_balance:float,overpaid_amount:float,payment_status:string,is_fully_paid:bool,pending_verification_status:?string,has_pending_proof:bool,additional_payment_amount:float,additional_payment_status:?string,refund_amount:float,refund_status:?string}
     */
    public static function summarize(Order $order): array
    {
        $requestedDp = (float) $order->dp_amount;
        $totalPaid = (float) $order->paid_amount;
        $grandTotal = (float) $order->total_amount;

        // UAT-004: pending submitted manual-transfer amount (DP Diajukan) —
        // read-only projection over the canonical verification rows. A
        // pending submission exists exactly when the order's CURRENT manual
        // transaction carries a 'pending' BankTransferVerification; its
        // amount is the submitted nominal, never paid money.
        //
        // "CURRENT manual transaction" is the canonical rule this whole system
        // already uses to decide what a verification actUALLY applies to
        // (PaymentController::latestManualTransaction — the most recent manual
        // transaction by id, because a DP order has several: the DP itself and
        // then the settlement). Scoping the projection the same way is what
        // keeps the readout and the action from disagreeing: a verification row
        // left behind on a SUPERSEDED transaction (e.g. a DP proof still pending
        // while the later settlement proof was already verified) must not keep
        // reporting "Menunggu Verifikasi / DP Diajukan" on an order that is
        // already LUNAS — the previous "latest pending verification of ANY
        // transaction" rule did exactly that.
        $pendingVerification = self::pendingVerificationForCurrentManualTransaction($order->id);
        $submittedDp = 0.0;
        $pendingStatus = null;
        $hasPendingProof = false;
        if ($pendingVerification) {
            $pendingStatus = 'pending';
            $hasPendingProof = $pendingVerification->proof_image_path !== null;
            $submittedDp = (float) PaymentTransaction::query()
                ->whereKey($pendingVerification->payment_transaction_id)
                ->value('amount');
        } elseif (self::hasPendingCodProof($order->id)) {
            // A pending COD proof is also "diajukan, menunggu verifikasi" —
            // surfaced with the same status vocabulary, amount 0 (COD has
            // no partial nominal; collection happens on delivery).
            $pendingStatus = 'pending';
            $hasPendingProof = true;
        }

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
            // UAT-004: submitted-but-unverified nominal ("DP Diajukan") —
            // informational only, never added to total_paid/verified_dp.
            'submitted_dp' => $submittedDp,
            'total_paid' => $totalPaid,
            'remaining_balance' => (float) $order->remaining_amount,
            'overpaid_amount' => max(0.0, $totalPaid - $grandTotal),
            'payment_status' => $order->payment_status,
            'is_fully_paid' => $order->isFullyPaid(),
            // UAT-004: pending-verification triage signal for Finance.
            'pending_verification_status' => $pendingStatus,
            'has_pending_proof' => $hasPendingProof,
            'additional_payment_amount' => $pendingAdditional,
            'additional_payment_status' => $pendingAdditional > 0 ? 'pending' : $latestAdditional?->status,
            'refund_amount' => $pendingRefund,
            'refund_status' => $pendingRefund > 0 ? 'pending' : $latestAdjustment?->refund_status,
        ];
    }

    /**
     * The order's CURRENT manual transaction — the exact row the verification/settlement actions
     * operate on. Mirrors PaymentController::latestManualTransaction (latest by id, NOT created_at:
     * the DP and its settlement are typically created in the same second) so the projection and the
     * money-moving action can never read different "current" rows.
     */
    private static function currentManualTransactionId(int $orderId): ?int
    {
        return PaymentTransaction::query()
            ->where('order_id', $orderId)
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'manual'))
            ->orderByDesc('id')
            ->value('id');
    }

    /** A pending manual-transfer proof on the order's current manual transaction, or null. */
    private static function pendingVerificationForCurrentManualTransaction(int $orderId): ?\App\Models\BankTransferVerification
    {
        $transactionId = self::currentManualTransactionId($orderId);

        if ($transactionId === null) {
            return null;
        }

        return \App\Models\BankTransferVerification::query()
            ->where('payment_transaction_id', $transactionId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A pending COD proof ("diajukan, menunggu konfirmasi") — same triage
     * vocabulary as a pending bank-transfer verification, no nominal.
     *
     * Scoped to the current COD transaction for the same reason as the manual
     * branch: a confirmed/rejected proof on a superseded transaction must not
     * keep the order looking like it is still awaiting confirmation.
     */
    private static function hasPendingCodProof(int $orderId): bool
    {
        $transactionId = PaymentTransaction::query()
            ->where('order_id', $orderId)
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'cod'))
            ->orderByDesc('id')
            ->value('id');

        if ($transactionId === null) {
            return false;
        }

        return \App\Models\CodPaymentProof::query()
            ->where('payment_transaction_id', $transactionId)
            ->where('status', 'pending')
            ->exists();
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
