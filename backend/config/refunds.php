<?php

return [
    /*
     * Reporting read-switch (design §8 / N-06).
     *
     * legacy  — existing aggregate readers (order_item_adjustments/return_items);
     *           keep this during the deployment window before a ledger backfill has been applied.
     * ledger  — R_c: only proven, affects_paid_amount payouts from the refund ledger.
     *
     * Flip to "ledger" only after the backfill batch is accepted; flip back to "legacy" for a
     * rollback. The A=24/B=15 report column contracts are unchanged either way.
     */
    'reporting_source' => env('REFUNDS_REPORTING_SOURCE', 'legacy'),
];
