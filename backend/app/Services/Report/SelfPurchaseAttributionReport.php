<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\DB;

/**
 * G-13 (Owner decision 2026-10-30): READ-ONLY reconciliation of historical
 * sales-kurir-sub self-purchase fee attribution.
 *
 * The current rule (OrderService::priceAndReserveLine) books the sales fee to
 * the BUYER when the buyer is itself a seller — for a sales-kurir-sub
 * self-purchase the SKS is the beneficiary of its own sales fee. Historical
 * rows created under the OLD rule may have booked that fee to the buyer's
 * korsal/agen upline instead.
 *
 * This service only IDENTIFIES those rows from the historical transaction
 * snapshot (commissions + orders + users, all point-in-time). It never
 * changes an order, commission, ledger or adjustment row: no backfill, no
 * financial correction, no audit write. Any correction is a separate
 * Owner-approved change. Console-scope-free by design (raw builder, no
 * Eloquent global scopes) — same pattern as RetentionPlanner.
 */
final class SelfPurchaseAttributionReport
{
    /** G-13 scope: the SKS buyer (a konsumen-role order may never match). */
    public const BUYER_ROLE = 'sales-kurir-sub';

    /**
     * One row per mismatched sales-fee commission line: the order's buyer is
     * a sales-kurir-sub, the line is a sales fee, and the beneficiary is NOT
     * the buyer (the old rule booked it to an upline). Agent-fee lines and
     * correctly self-attributed lines never appear.
     *
     * @return list<array<string,mixed>>
     */
    public function mismatchLines(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return DB::table('commissions as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->join('users as b', 'b.id', '=', 'o.konsumen_id')
            ->join('roles as br', 'br.id', '=', 'b.role_id')
            ->leftJoin('users as f', 'f.id', '=', 'c.beneficiary_user_id')
            ->where('br.slug', self::BUYER_ROLE)
            ->where('c.beneficiary_role', 'sales')
            ->whereColumn('c.beneficiary_user_id', '!=', 'o.konsumen_id')
            ->orderBy('o.id')->orderBy('c.id')
            ->get([
                'o.id as order_id', 'o.order_no', 'o.agent_id', 'o.sales_id', 'o.korsal_id',
                'o.status as order_status', 'o.created_at as order_created_at',
                'o.konsumen_id as buyer_user_id', 'b.name as buyer_name',
                'c.id as commission_id', 'c.order_item_id',
                'c.beneficiary_user_id', 'f.name as beneficiary_name',
                'c.amount', 'c.status as commission_status', 'c.earned_at',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Full read-only report: mismatched lines grouped per historical order,
     * totals, and the standing correction policy (never applied here).
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $lines = $this->mismatchLines();

        $orders = [];
        foreach ($lines as $line) {
            $orders[$line['order_no']] ??= [
                'order_id' => $line['order_id'],
                'order_no' => $line['order_no'],
                'order_status' => $line['order_status'],
                'order_created_at' => $line['order_created_at'],
                'agent_id' => $line['agent_id'],
                // Historical artifact of the old attribution: legacy self-purchase
                // rows carry sales_id = NULL with konsumen_id = the seller.
                'sales_id' => $line['sales_id'],
                'korsal_id' => $line['korsal_id'],
                'buyer' => ['user_id' => $line['buyer_user_id'], 'name' => $line['buyer_name'], 'role' => self::BUYER_ROLE],
                'mismatched_sales_fee_amount' => 0.0,
                'commission_lines' => [],
            ];

            $orders[$line['order_no']]['mismatched_sales_fee_amount'] += (float) $line['amount'];
            $orders[$line['order_no']]['commission_lines'][] = [
                'commission_id' => $line['commission_id'],
                'order_item_id' => $line['order_item_id'],
                'beneficiary_user_id' => $line['beneficiary_user_id'],
                'beneficiary_name' => $line['beneficiary_name'],
                'amount' => (float) $line['amount'],
                'status' => $line['commission_status'],
                'earned_at' => $line['earned_at'],
            ];
        }

        return [
            'mode' => 'READ-ONLY reconciliation report (G-13, Owner 2026-10-30): identifies historical SKS self-purchase fee attribution discrepancies; NO data is changed',
            'generated_at' => now()->toIso8601String(),
            'scope' => 'orders whose buyer is a sales-kurir-sub and whose sales-fee commission line is booked to someone other than the buyer',
            'policy' => 'current rule: a sales-kurir-sub self-purchase books the sales fee to the BUYER; rows where the historical fee went elsewhere are listed here',
            'totals' => [
                'mismatched_commission_lines' => count($lines),
                'mismatched_orders' => count($orders),
                'mismatched_sales_fee_amount' => array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)),
            ],
            'orders' => array_values($orders),
            'correction' => 'NOT APPLIED — no backfill and no financial correction runs from this report; any change to orders, commissions or the ledger requires a separate Owner approval',
        ];
    }

    private function tablesReady(): bool
    {
        foreach (['commissions', 'orders', 'users', 'roles'] as $t) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($t)) {
                return false;
            }
        }

        return true;
    }
}
