<?php

namespace App\Console\Commands;

use App\Support\CollectibleReceivable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY evidence for WS-7 / OD-K5-4 steps 2–3: the previously reported outstanding (stored `remaining_amount` of unpaid /
 * pending_verification / partially_paid orders) against the canonical collectible receivable, attributed by Agent, payment status
 * and order status. Writes nothing.
 */
class ArReconciliationReport extends Command
{
    protected $signature = 'primeclassy:ar-reconciliation-report';

    protected $description = 'DRY-RUN: stored remaining_amount vs canonical collectible AR, by agent / payment status / order status (changes nothing)';

    public function handle(): int
    {
        $canonical = CollectibleReceivable::sql('orders');
        $legacy = "(CASE WHEN orders.payment_status IN ('unpaid','pending_verification','partially_paid') THEN orders.remaining_amount ELSE 0 END)";
        $over = CollectibleReceivable::overpaymentSql('orders');

        $rows = DB::table('orders')
            ->select('agent_id', 'payment_status', 'status')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw("ROUND(SUM($legacy), 2) as legacy_outstanding")
            ->selectRaw("ROUND(SUM($canonical), 2) as canonical_collectible")
            ->selectRaw("ROUND(SUM($canonical) - SUM($legacy), 2) as difference")
            ->selectRaw("ROUND(SUM($over), 2) as overpayment_liability")
            ->groupBy('agent_id', 'payment_status', 'status')
            ->havingRaw('ABS(SUM('.$canonical.') - SUM('.$legacy.')) > 0.004 OR SUM('.$over.') > 0')
            ->orderBy('agent_id')->orderBy('payment_status')->orderBy('status')
            ->get();

        $totals = DB::table('orders')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw("ROUND(SUM($legacy), 2) as legacy_outstanding")
            ->selectRaw("ROUND(SUM($canonical), 2) as canonical_collectible")
            ->selectRaw("ROUND(SUM($over), 2) as overpayment_liability")
            ->first();

        $this->line(json_encode([
            'mode' => 'DRY-RUN (read-only)',
            'totals' => $totals,
            'groups_with_differences' => $rows,
            'note' => 'canonical = 0 for cancelled orders, else max(0, total - paid); overpayment is reported separately and never netted (OD-K5-1/3).',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
