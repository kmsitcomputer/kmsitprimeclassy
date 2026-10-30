<?php

namespace App\Services\Report;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Support\CollectibleReceivable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Network transaction report (Owner directive 2026-10-29 §7).
 *
 * Locked rule: a Sales/SKS/Korsal/Agen "jumlah transaksi" is the TOTAL IN
 * THEIR NETWORK SCOPE, not only personal orders. One Order row is counted
 * exactly once (AC-13): the query is ORDER-grained (never joined to items /
 * payments / commissions), attribution comes from the same persisted order
 * fields the transaction report displays, and grouping happens in PHP over
 * distinct order ids.
 *
 * Attribution (source-verified, never guessed):
 *   sales   = buyer themself when the buyer holds a seller role (self-purchase,
 *             BR §15/D1) else orders.sales_id
 *   korsal  = buyer themself when the buyer is that Korsal else orders.korsal_id
 *   agen    = orders.agent_id (always set; BelongsToAgentScope invariant)
 * Orders without a sales/korsal relation are kept VISIBLE in separate
 * "TANPA SALES"/"TANPA KORSAL" groups (owner: unattributed must not vanish or
 * leak into another network).
 *
 * Money columns reuse canonical derivations only: totals/paid are stored
 * order columns, outstanding is CollectibleReceivable (WS-7). Same period +
 * scope ⇒ identical numbers to /reports/transactions aggregates.
 */
class NetworkTransactionReportService
{
    private const MAX_ORDERS = 20000;

    public function report(\App\Models\User $actor, array $filters): array
    {
        $base = DB::table('orders as o')
            ->leftJoin('users as buyers', 'buyers.id', '=', 'o.konsumen_id')
            ->leftJoin('roles as buyer_roles', 'buyer_roles.id', '=', 'buyers.role_id')
            ->select([
                'o.id', 'o.order_no', 'o.status', 'o.payment_status',
                'o.total_amount', 'o.paid_amount',
                'o.agent_id', 'o.sales_id', 'o.korsal_id',
                'buyers.name as buyer_name',
            ])
            ->selectRaw('CASE WHEN buyer_roles.slug IN (\'agen\',\'korsal\',\'sales\',\'sales-kurir-sub\',\'sales-kurir\') THEN buyers.id ELSE o.sales_id END as sales_attr')
            ->selectRaw('CASE WHEN buyer_roles.slug = \'korsal\' THEN buyers.id ELSE o.korsal_id END as korsal_attr')
            ->selectRaw(CollectibleReceivable::sql('o').' as collectible')
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('o.created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('o.created_at', '<=', $filters['to']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('o.status', $filters['status']));

        // ---- network scope (role-locked; never widenable by request body) ----
        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            $base->whereRaw('CASE WHEN buyer_roles.slug IN (\'agen\',\'korsal\',\'sales\',\'sales-kurir-sub\',\'sales-kurir\') THEN buyers.id ELSE o.sales_id END = ?', [$actor->id]);
        } elseif ($actor->isRole('korsal')) {
            $base->where('o.agent_id', $actor->agent_id)
                ->whereRaw('CASE WHEN buyer_roles.slug = \'korsal\' THEN buyers.id ELSE o.korsal_id END = ?', [$actor->id]);
        } elseif ($actor->isRole('agen', 'admin')) {
            $base->where('o.agent_id', $actor->agent_id);
        } elseif ($actor->isRole('super_admin')) {
            if (! empty($filters['agent_id'])) {
                $base->where('o.agent_id', (int) $filters['agent_id']);
            }
        } else {
            throw new ApiException('Role ini tidak punya cakupan jaringan transaksi.', 403);
        }

        $count = (clone $base)->count();
        if ($count > self::MAX_ORDERS) {
            throw new ApiException('Periode terlalu luas untuk agregasi jaringan; persempit filter tanggal.', 422);
        }
        $orders = $base->get();

        $totals = [
            'order_count' => $orders->count(),
            'total_amount' => round((float) $orders->sum('total_amount'), 2),
            'paid_amount' => round((float) $orders->sum('paid_amount'), 2),
            'outstanding_amount' => round((float) $orders->sum('collectible'), 2),
        ];
        $byStatus = $orders->groupBy('status')->map(fn ($g) => [
            'status' => $g->first()->status,
            'order_count' => $g->count(),
            'total_amount' => round((float) $g->sum('total_amount'), 2),
        ])->values();

        // Breakdowns only where the role is allowed to see below its own node.
        $idName = fn (string $idColumn, string $nameColumn) => DB::table('users')
            ->pluck($nameColumn, $idColumn)->all();

        $korsalNames = $idName('id', 'name');
        $salesNames = $idName('id', 'name');

        $byKorsal = null;
        $bySales = null;

        $group = function (string $key) use ($orders) {
            return $orders->groupBy(fn ($o) => $o->{$key} ?: 0)->map(function ($g, $gid) {
                return [
                    'id' => $gid ? (int) $gid : null,
                    'order_count' => $g->count(),
                    'total_amount' => round((float) $g->sum('total_amount'), 2),
                    'paid_amount' => round((float) $g->sum('paid_amount'), 2),
                    'outstanding_amount' => round((float) $g->sum('collectible'), 2),
                ];
            })->values();
        };

        $seeSales = $actor->isRole('super_admin', 'agen', 'admin', 'korsal');
        $seeKorsal = $actor->isRole('super_admin', 'agen', 'admin');

        if ($seeSales) {
            $bySales = $group('sales_attr')->map(function ($row) use ($salesNames) {
                $row['name'] = $row['id'] ? ($salesNames[$row['id']] ?? '#'.$row['id']) : 'TANPA SALES (langsung/agen)';

                return $row;
            })->sortByDesc('order_count')->values();
        }
        if ($seeKorsal) {
            $byKorsal = $group('korsal_attr')->map(function ($row) use ($korsalNames) {
                $row['name'] = $row['id'] ? ($korsalNames[$row['id']] ?? '#'.$row['id']) : 'TANPA KORSAL';

                return $row;
            })->sortByDesc('order_count')->values();
        }

        return [
            'scope' => match (true) {
                $actor->isRole('super_admin') => 'semua cabang',
                $actor->isRole('agen', 'admin') => 'cabang sendiri',
                $actor->isRole('korsal') => 'jaringan korsal sendiri',
                default => 'jaringan sales sendiri',
            },
            'totals' => $totals,
            'by_status' => $byStatus,
            'by_korsal' => $byKorsal,
            'by_sales' => $bySales,
        ];
    }
}
