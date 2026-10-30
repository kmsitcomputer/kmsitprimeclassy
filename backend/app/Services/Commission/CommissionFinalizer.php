<?php

namespace App\Services\Commission;

use App\Models\Commission;

/**
 * Pending -> final for fees (Owner decision, Phase 2 directive): every fee row is born `pending` and becomes final only once
 * ITS ORDER ITEM has actually been delivered (`terkirim`). Fees are per order item, so finalisation is per item too — delivering
 * one item of a multi-item order finalises that item's agent / sales / courier rows and leaves the siblings pending.
 *
 * `approved` is the existing non-paid, non-reversed state of the `commissions.status` enum and is what "final" means here:
 * the entitlement is confirmed; payout (`paid`) happens outside the application and has no writer (BUSINESS-RULES §15a).
 * Rows that are already approved / paid / reversed are never touched, so the call is idempotent and cannot undo a reversal.
 */
class CommissionFinalizer
{
    /**
     * @param  iterable<int>  $orderItemIds  items that have reached `terkirim`
     * @return int number of fee rows finalised
     */
    public function finalizeItems(iterable $orderItemIds): int
    {
        $ids = collect($orderItemIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return Commission::query()
            ->whereIn('order_item_id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'approved', 'updated_at' => now()]);
    }
}
