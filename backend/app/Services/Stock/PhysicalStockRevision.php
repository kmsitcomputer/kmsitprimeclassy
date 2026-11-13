<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/** Change token, not a balance/ledger or a new inventory ordering. DB triggers own writes. */
class PhysicalStockRevision
{
    public function current(int $agentId, string $type, ?int $locationId, ?int $productId, ?int $variationId): int
    {
        $key = implode(':', [$agentId, $type, $locationId ?? 0, $variationId === null ? 'p' : 'v', $variationId ?? $productId ?? 0]);

        // Materialise only a zero change token, never a physical stock row.
        // This avoids retaining a missing-counter gap lock before a later stock
        // insert fires its trigger, including simultaneous new targets.
        DB::table('warehouse_stock_revisions')->insertOrIgnore(['target_key' => $key, 'revision' => 0]);

        // Caller holds the canonical warehouse target lock first (and Sub parent
        // where applicable). A locking read avoids REPEATABLE READ snapshots.
        return (int) (DB::table('warehouse_stock_revisions')->where('target_key', $key)->lockForUpdate()->value('revision') ?? 0);
    }
}
