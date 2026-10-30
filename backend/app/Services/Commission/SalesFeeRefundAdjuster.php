<?php

namespace App\Services\Commission;

use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\OrderItem;
use App\Models\ReturnItem;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * WS-8 (Owner decision 2026-10-30, FINAL): Refund dan Fee Sales.
 *
 * "Fee yang dibatalkan = Σ(jumlah unit produk yang direfund × snapshot Fee Sales
 * per unit produk tersebut)."
 *
 * Rules implemented here exactly as decided:
 * - The SOURCE of truth for the fee is the TRANSACTION SNAPSHOT (commissions, written per order
 *   item at checkout from order_items.sales_fee_amount = per-unit fee × quantity; a later change
 *   to product fee configuration never touches it). No current product fee is ever read.
 * - FULL refund (all units) cancels the item's whole Sales fee; PARTIAL refunds cancel exactly
 *   units × per-unit fee. Units/lines that are NOT refunded never lose fee.
 * - The REFUND AMOUNT (money given back to the buyer) is never used — the per-unit fee snapshot is,
 *   so "no percentage of the refund nominal" is guaranteed by construction.
 * - DOUBLE ADJUSTMENT is impossible: one adjustment row per (commission, return item) enforced by
 *   a DB unique index + firstOrCreate replay-safety, the same way a refund row settles only once.
 * - ONLY the Sales fee is cancelled — agent/courier fee contracts are untouched (Owner: "Jangan
 *   mengubah kontrak fee role lain").
 * - The ledger stays HISTORICAL: original commission rows are immutable; the cancellation is a
 *   SIGNED NEGATIVE row in commission_adjustments, so SUM(commission.amount) + SUM(adjustment.amount)
 *   is the net entitlement for reconciliation and payout at the end of the fee period.
 * - A REFUSED refund never reaches this service (the caller hooks the single place a return item
 *   becomes `refund_status = processed`), so rejection can never cancel a fee.
 * - A refund can never cancel more fee than the item earned (number cruncher is clamped to the
 *   still-outstanding fee, so repeated partial refunds that somehow exceed the units can never
 *   flip a fee negative).
 */
class SalesFeeRefundAdjuster
{
    public const POLICY_VERSION = 'ws8-owner-2026-10-30';

    /**
     * Cancel the Sales fee for the units refunded by ONE processed return line.
     * MUST be called inside the refund-settlement transaction that already holds
     * locks on the return item and its order — this service itself re-locks the
     * refund line and its commission rows so a concurrent second settlement
     * either sees `processed` (refused by the caller) or serialises here.
     */
    public function adjustForProcessedReturn(ReturnItem $returnItem, User $actor): void
    {
        $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->first();
        $item = $returnItem ? OrderItem::query()
            ->withoutGlobalScopes()
            ->whereKey($returnItem->order_item_id)
            ->lockForUpdate()
            ->first() : null;

        if (! $returnItem || ! $item || $returnItem->quantity_returned <= 0 || $item->original_quantity <= 0) {
            return;
        }

        // Only the Sales fee contract moves. A refund of agent/courier fees is
        // NOT authorized (directive: "Jangan mengubah kontrak fee role lain").
        Commission::query()
            ->where('order_item_id', $item->id)
            ->where('beneficiary_role', 'sales')
            ->orderBy('id')
            ->lockForUpdate()
            ->each(fn (Commission $commission) => $this->cancelUnits($commission, $item, $returnItem, $actor));
    }

    private function cancelUnits(Commission $commission, OrderItem $item, ReturnItem $returnItem, User $actor): void
    {
        // Replay safety before any math: this (commission, processed return line) pair may
        // never produce a second entry. Unique index is the hard guarantee; this read keeps
        // a retry quiet and lets a plain rerun settle the same state.
        if (CommissionAdjustment::query()->where('commission_id', $commission->id)->where('return_item_id', $returnItem->id)->exists()) {
            return;
        }

        $perUnitFee = $this->perUnitFee($commission->amount, (int) $item->original_quantity);
        if ($perUnitFee <= 0) {
            return;
        }

        // Already-cancelled fee on this commission row (signed ledger: reversals are negative).
        $cancelledSoFar = (float) CommissionAdjustment::query()
            ->where('commission_id', $commission->id)
            ->sum(DB::raw('-amount'));
        if ($cancelledSoFar < 0) {
            $cancelledSoFar = 0; // defensive: a future positive adjustment must not enlarge the ceiling
        }

        $requested = round($perUnitFee * $returnItem->quantity_returned, 2);
        $outstanding = round(max(0, (float) $commission->amount - $cancelledSoFar), 2);
        $cancel = round(min($requested, $outstanding), 2);

        if ($cancel <= 0) {
            return;
        }

        CommissionAdjustment::create([
            'commission_id' => $commission->id,
            'order_id' => $commission->order_id,
            'order_item_id' => $commission->order_item_id,
            'beneficiary_user_id' => $commission->beneficiary_user_id,
            'beneficiary_role' => 'sales',
            'return_item_id' => $returnItem->id,
            'amount' => -$cancel,
            'reason' => sprintf('sales_fee_refund: units=%d return_id=%d', $returnItem->quantity_returned, $returnItem->return_id),
            'policy_version' => self::POLICY_VERSION,
            'created_by' => $actor->id,
        ]);

        ActivityLogger::log($actor->id, $returnItem, 'commission.sales_fee_refund_adjusted', null, [
            'return_id' => $returnItem->return_id,
            'return_item_id' => $returnItem->id,
            'order_item_id' => $item->id,
            'commission_id' => $commission->id,
            'units' => $returnItem->quantity_returned,
            'amount' => -$cancel,
            'policy_version' => self::POLICY_VERSION,
        ]);
    }

    /** Whole-line fee snapshot ÷ original units = the per-unit snapshot ("fee ditentukan per produk"). */
    private function perUnitFee($lineFee, int $originalQuantity): float
    {
        if ($originalQuantity <= 0) {
            return 0.0;
        }

        return round((float) $lineFee / $originalQuantity, 2);
    }
}
