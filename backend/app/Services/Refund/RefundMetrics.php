<?php

namespace App\Services\Refund;

use App\Models\Order;
use App\Models\OrderSalesReduction;
use App\Models\RefundObligation;
use App\Models\RefundPayout;

/**
 * The financial model of design §5.1, computed from the ledger with integer cents.
 *
 * G gross received, A applied refunds, P net cash retained, R_c proven refunds,
 * R_u applied-but-unverified, R_r reserved remainder, B effective billable, C available cash.
 */
class RefundMetrics
{
    /**
     * @return array{
     *   gross_cents:int, paid_cents:int, applied_cents:int, proven_cents:int,
     *   unverified_cents:int, reserved_cents:int, billable_cents:int,
     *   outstanding_cents:int, overpayment_cents:int, available_cents:int
     * }
     */
    public function forOrder(Order $order): array
    {
        $gross = Money::cents($order->gross_received_amount);
        $paid = Money::cents($order->paid_amount);

        $applied = Money::cents(RefundPayout::query()
            ->where('order_id', $order->id)
            ->where('affects_paid_amount', true)
            ->sum('amount'));

        $proven = Money::cents(RefundPayout::query()
            ->where('order_id', $order->id)
            ->where('affects_paid_amount', true)
            ->where('verification_status', 'proven')
            ->sum('amount'));

        $reserved = 0;
        $open = RefundObligation::query()
            ->where('order_id', $order->id)
            ->whereIn('status', RefundObligation::OPEN_STATUSES)
            ->withSum('payouts as paid_total', 'amount')
            ->get();

        foreach ($open as $obligation) {
            $reserved += max(0, Money::cents($obligation->amount) - Money::cents($obligation->paid_total));
        }

        $postInvoiceReductions = Money::cents(OrderSalesReduction::query()
            ->where('order_id', $order->id)
            ->where('applied_to_order_total', false)
            ->whereNull('reinstated_at')
            ->sum('amount'));

        $billable = max(0, Money::cents($order->total_amount) - $postInvoiceReductions);
        $outstanding = max(0, $billable - $paid);
        $overpayment = max(0, $paid - $billable);
        $available = max(0, $paid - $billable - $reserved);

        return [
            'gross_cents' => $gross,
            'paid_cents' => $paid,
            'applied_cents' => $applied,
            'proven_cents' => $proven,
            'unverified_cents' => max(0, $applied - $proven),
            'reserved_cents' => $reserved,
            'billable_cents' => $billable,
            'outstanding_cents' => $outstanding,
            'overpayment_cents' => $overpayment,
            'available_cents' => $available,
        ];
    }

    /**
     * R_c over an order scope: only payouts that are proven AND affect paid truth (D-01).
     *
     * @param  iterable<int|string>  $orderIds
     */
    public function provenCentsForOrders(iterable $orderIds, ?array $sourceTypes = null): int
    {
        $ids = collect($orderIds)->all();
        if ($ids === []) {
            return 0;
        }

        $query = RefundPayout::query()
            ->whereIn('order_id', $ids)
            ->where('affects_paid_amount', true)
            ->where('verification_status', 'proven');

        if ($sourceTypes !== null) {
            $query->whereIn('obligation_id', RefundObligation::query()
                ->whereIn('order_id', $ids)
                ->whereIn('source_type', $sourceTypes)
                ->select('id'));
        } else {
            $query->whereNotIn('obligation_id', RefundObligation::query()
                ->whereIn('order_id', $ids)
                ->whereIn('source_type', ['return_stage', 'return_approval'])
                ->select('id'));
        }

        return Money::cents($query->sum('amount'));
    }

    /** @return array<string, float> */
    public function forOrderDisplay(Order $order): array
    {
        return array_map(Money::float(...), $this->forOrder($order));
    }
}
