<?php

namespace App\Support;

/**
 * Canonical collectible receivable (BUSINESS-RULES §20c, OD-K5-1…5; WS-7). The ONE derivation every report / dataset uses:
 *
 *   collectible = 0 for a cancelled order, otherwise GREATEST(total_amount − COALESCE(paid_amount, 0), 0)      (M-2)
 *
 * It replaces summing the stored `orders.remaining_amount` (KF-1: 0 for a non-DP order that never had a money event, so the
 * receivable was undercounted; KF-2: no consumer excluded cancelled orders). Nothing is stored or backfilled (OD-K5-5), the payment
 * lifecycle that writes `remaining_amount` is untouched, a payment awaiting verification is NOT payment so its balance stays
 * collectible (OD-K5-2), and an overpayment (paid > total) is its own liability, never netted here (OD-K5-3).
 */
final class CollectibleReceivable
{
    public const CANCELLED_STATUS = 'dibatalkan';

    /** PHP twin of sql() for one loaded order (same M-2 formula; never reads the stored `remaining_amount`). */
    public static function forOrder(\App\Models\Order $order): float
    {
        if ($order->status === self::CANCELLED_STATUS) {
            return 0.0;
        }

        return round(max((float) $order->total_amount - (float) ($order->paid_amount ?? 0), 0.0), 2);
    }

    /** SQL (MySQL) for one order row; `$alias` is the orders table name/alias in the surrounding query. */
    public static function sql(string $alias = 'orders'): string
    {
        return "(CASE WHEN {$alias}.status = '".self::CANCELLED_STATUS."' THEN 0 ELSE GREATEST({$alias}.total_amount - COALESCE({$alias}.paid_amount, 0), 0) END)";
    }

    /** Overpayment liability (OD-K5-3): shown separately, never netted against receivables. */
    public static function overpaymentSql(string $alias = 'orders'): string
    {
        return "(CASE WHEN {$alias}.status = '".self::CANCELLED_STATUS."' THEN 0 ELSE GREATEST(COALESCE({$alias}.paid_amount, 0) - {$alias}.total_amount, 0) END)";
    }
}
