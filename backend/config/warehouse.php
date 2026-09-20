<?php

return [
    // Warehouse is the safe operational default after the production cutover.
    // Explicit overrides remain useful for isolated compatibility tests and
    // recovery tooling; omitting the variable must never reopen legacy writes.
    'authoritative' => (bool) env('WAREHOUSE_STOCK_AUTHORITATIVE', true),
];
