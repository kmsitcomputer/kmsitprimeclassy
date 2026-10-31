<?php

namespace App\Services\Invoice;

use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\OrderInvoiceSnapshot;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Owner-locked invoice policy (Improvement 11). The invoice number is, and stays, `orders.order_no`.
 *
 *  - COD: issued when the order is successfully created (no payment needed).
 *  - Bank Transfer / DP: issued when the FIRST payment is verified by Keuangan (partial, DP or full).
 *    A pending or rejected first payment issues nothing.
 *  - Exactly one main invoice per order: `invoice_issued_at` is set once and never moved. Later
 *    installments and the payoff only append an immutable snapshot version (balance history).
 *
 * Callers MUST already hold the Order row lock (every PaymentService writer and createOrder does), which
 * is what makes issuance idempotent under concurrency.
 */
class InvoiceIssuanceService
{
    /** Issue (once) and/or record a balance snapshot. Returns true when the invoice was issued by THIS call. */
    public function syncAfterChange(Order $order, string $trigger, ?int $actorId = null): bool
    {
        $order->refresh();
        $issuedNow = false;

        if ($order->invoice_issued_at === null) {
            if (! $this->isEligible($order)) {
                return false;
            }

            $order->forceFill(['invoice_issued_at' => now()])->save();
            $issuedNow = true;
            $trigger = $order->paymentMethod?->type === 'cod' ? 'order_created_cod' : 'first_payment_verified';
        }

        $this->snapshotIfChanged($order, $trigger);

        if ($issuedNow) {
            ActivityLogger::log($actorId, $order, 'invoice.issued', null, [
                'invoice_number' => $order->order_no, 'trigger' => $trigger,
            ]);
        }

        return $issuedNow;
    }

    /** Eligible = COD order, or any order with at least one verified payment. */
    public function isEligible(Order $order): bool
    {
        if ($order->status === 'dibatalkan' && (float) $order->paid_amount <= 0) {
            return false;
        }

        if ($order->paymentMethod?->type === 'cod') {
            return true;
        }

        return (float) $order->gross_received_amount > 0 || (float) $order->paid_amount > 0
            || BankTransferVerification::query()
                ->whereIn('payment_transaction_id', $order->paymentTransactions()->select('id'))
                ->where('status', 'verified')->exists();
    }

    /**
     * Legacy orders (created before issuance tracking) are eligible but carry no timestamp; stamp them
     * lazily on first access, without ever moving an existing timestamp.
     */
    public function ensureIssuedIfEligible(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->invoice_issued_at !== null) {
                return true;
            }

            return $this->syncAfterChange($locked, 'lazy_legacy') || $locked->fresh()->invoice_issued_at !== null;
        });
    }

    private function snapshotIfChanged(Order $order, string $trigger): void
    {
        $outstanding = max(0.0, round((float) $order->total_amount - (float) $order->paid_amount, 2));
        $last = OrderInvoiceSnapshot::query()->where('order_id', $order->id)->orderByDesc('version')->first();

        if ($last
            && (float) $last->total_amount === round((float) $order->total_amount, 2)
            && (float) $last->verified_paid_amount === round((float) $order->paid_amount, 2)
            && $last->payment_status === $order->payment_status
            && optional($last->due_date)->toDateString() === optional($order->due_date)->toDateString()) {
            return;
        }

        OrderInvoiceSnapshot::create([
            'order_id' => $order->id,
            'version' => ($last?->version ?? 0) + 1,
            'trigger' => $trigger,
            'invoice_number' => $order->order_no,
            'total_amount' => $order->total_amount,
            'verified_paid_amount' => $order->paid_amount,
            'outstanding_amount' => $outstanding,
            'payment_status' => $order->payment_status,
            'due_date' => $order->due_date,
        ]);
    }
}
