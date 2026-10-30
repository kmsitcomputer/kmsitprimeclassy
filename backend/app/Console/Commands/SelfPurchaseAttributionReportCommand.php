<?php

namespace App\Console\Commands;

use App\Services\Report\SelfPurchaseAttributionReport;
use Illuminate\Console\Command;

/**
 * G-13 (Owner decision 2026-10-30): read-only reconciliation of historical
 * SKS self-purchase fee attribution. There is intentionally NO --execute
 * option — no backfill and no financial correction may ever run from this
 * command; corrections need a separate Owner-approved change.
 */
class SelfPurchaseAttributionReportCommand extends Command
{
    protected $signature = 'primeclassy:selfpurchase-attribution-report';

    protected $description = 'READ-ONLY: list historical sales-kurir-sub self-purchase orders whose sales fee was booked to someone other than the buyer (changes nothing)';

    public function handle(SelfPurchaseAttributionReport $report): int
    {
        $this->line(json_encode($report->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
