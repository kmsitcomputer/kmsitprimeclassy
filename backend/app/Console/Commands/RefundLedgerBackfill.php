<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItemAdjustment;
use App\Models\RefundAttempt;
use App\Models\RefundMigrationBatch;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ReturnItem;
use App\Models\User;
use App\Services\Refund\Money;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P4 legacy backfill (design §10). Dry-run by default; --apply is refused unless the connected
 * database is a *_test database. Insert-or-skip by stable legacy keys, per-chunk transactions,
 * never touches paid_amount/subtotal/total, never fabricates an unallocatable basis.
 */
class RefundLedgerBackfill extends Command
{
    protected $signature = 'refunds:backfill-ledger
        {--apply : Write the backfill (test databases only)}
        {--batch=refund-ledger-v1 : Batch key; a rerun with the same key resumes insert-or-skip}
        {--chunk=200 : Orders per transaction}
        {--limit=0 : Maximum candidate orders (0 = all)}';

    protected $description = 'Backfill the refund ledger from legacy adjustments/returns (dry-run by default).';

    public function handle(
        OrderFinancialSnapshotService $snapshots,
        RefundAudit $audit,
    ): int {
        $apply = (bool) $this->option('apply');
        $database = DB::connection()->getDatabaseName();

        if ($apply && ! preg_match('/_test(ing)?$/', $database)) {
            $this->error("Apply mode is forbidden on '{$database}'. It may only run against a *_test database.");
            return self::FAILURE;
        }

        $batchKey = (string) $this->option('batch');
        $mode = $apply ? 'apply' : 'dry_run';
        $batch = RefundMigrationBatch::query()->where('batch_key', $batchKey.':'.$mode)->first();

        if ($apply && $batch && in_array($batch->status, ['completed', 'running'], true)) {
            $this->warn("Batch {$batchKey}:apply is {$batch->status}; rerunning insert-or-skip.");
        }

        if (! $batch) {
            $batch = RefundMigrationBatch::create([
                'batch_key' => $batchKey.':'.$mode,
                'mode' => $mode,
                'status' => $apply ? 'running' : 'dry_run',
                'started_at' => now(),
            ]);
        }

        $stats = $this->emptyStats();

        $query = Order::query()
            ->where(function ($q) {
                $q->where('paid_amount', '>', 0)
                    ->orWhereHas('items.adjustments')
                    ->orWhereHas('returnRequests.items', fn ($r) => $r->where('refund_status', 'processed'));
            })
            ->orderBy('id');

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            // chunkById() overrides an outer LIMIT and would process every candidate; resolve
            // the controlled candidate set first so --limit is a real bound for rehearsals.
            $query->whereIn('id', (clone $query)->limit($limit)->pluck('id')->all());
        }

        $chunk = max(1, (int) $this->option('chunk'));

        $query->chunkById($chunk, function ($orders) use ($apply, $batch, $snapshots, $audit, &$stats) {
            if (! $apply) {
                foreach ($orders as $order) {
                    $this->inspect($order, $stats);
                }

                return;
            }

            DB::transaction(function () use ($orders, $batch, $snapshots, $audit, &$stats) {
                foreach ($orders as $order) {
                    $this->applyOne($order, $batch, $snapshots, $audit, $stats);
                }
            });
        });

        $stats['database'] = $database;
        $stats['mode'] = $mode;

        $batch->update([
            'status' => $apply ? 'completed' : 'dry_run',
            'finished_at' => now(),
            'stats' => $stats,
        ]);

        $this->render($stats, $mode);

        return self::SUCCESS;
    }

    private function inspect(Order $order, array &$stats): void
    {
        $stats['orders_scanned']++;

        $adjustments = OrderItemAdjustment::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->where('refund_status', 'processed')
            ->get();

        foreach ($adjustments as $adjustment) {
            $stats['legacy_processed_adjustments']++;
            if (RefundObligation::query()->where('obligation_key', 'legacy:order_item_adjustment:'.$adjustment->id)->exists()) {
                $stats['duplicate_keys']++;
            } else {
                $stats['payouts_pending']++;
            }
        }

        $returns = ReturnItem::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->where('refund_status', 'processed')
            ->get();

        foreach ($returns as $return) {
            $stats['legacy_processed_return_items']++;
            if (RefundObligation::query()->where('obligation_key', 'legacy:return_item:'.$return->id)->exists()) {
                $stats['duplicate_keys']++;
            } else {
                $stats['verification_pending']++;
            }
        }

        if (! OrderFinancialSnapshot::query()->where('order_id', $order->id)->exists()) {
            $stats['snapshots_pending']++;

            if (! $this->hasBillableUnits($order)) {
                $stats['unallocatable_orders']++;
            }
        }

        if ($this->legacyAppliedCents($order) < 0) {
            $stats['gross_p_vs_g_minus_a'] = 'divergent';
        }
    }

    private function applyOne(
        Order $order,
        RefundMigrationBatch $batch,
        OrderFinancialSnapshotService $snapshots,
        RefundAudit $audit,
        array &$stats,
    ): void {
        $stats['orders_scanned']++;
        $actor = null;

        if (! OrderFinancialSnapshot::query()->where('order_id', $order->id)->exists() && $this->hasBillableUnits($order)) {
            // Legacy bases are reconstructed, never verified: provisional_legacy keeps every
            // consumption path on the needs_verification track (no invented auto-refund).
            $snapshots->ensure(
                $order,
                OrderFinancialSnapshotService::TRIGGER_PROVISIONAL_LEGACY,
                null,
                'provisional_legacy',
                $batch->id,
            );
            $stats['snapshots_created']++;
        } elseif (! $this->hasBillableUnits($order)) {
            $stats['unallocatable_orders']++;
        }

        foreach ($order->items()->pluck('id') as $itemId) {
            foreach (OrderItemAdjustment::query()->where('order_item_id', $itemId)->where('refund_status', 'processed')->get() as $adjustment) {
                $stats['legacy_processed_adjustments']++;
                $key = 'legacy:order_item_adjustment:'.$adjustment->id;

                if (RefundObligation::query()->where('obligation_key', $key)->exists()) {
                    $stats['duplicate_keys']++;
                    continue;
                }

                $amountCents = Money::cents($adjustment->refund_amount);
                if ($amountCents <= 0) {
                    $stats['unexplained_cash']++;
                    continue;
                }

                $obligation = RefundObligation::create([
                    'obligation_no' => 'RO-'.Str::ulid(),
                    'order_id' => $order->id,
                    'source_type' => 'legacy',
                    'source_id' => $adjustment->id,
                    'obligation_key' => $key,
                    'amount' => Money::amount($amountCents),
                    'status' => 'completed',
                    'provenance' => 'legacy',
                    'reason' => 'Legacy processing refund backfill',
                    'approved_at' => $adjustment->updated_at ?? now(),
                    'approval_source' => 'owner_manual_correction',
                    'completed_at' => $adjustment->updated_at ?? now(),
                    'deferred_amount' => '0.00',
                    'deferred_remaining' => '0.00',
                    'migration_batch_id' => $batch->id,
                    'legacy_source_type' => 'order_item_adjustment',
                    'legacy_source_id' => $adjustment->id,
                    'created_by' => $actor,
                    'metadata' => ['backfill' => true],
                ]);

                $attempt = RefundAttempt::create([
                    'obligation_id' => $obligation->id,
                    'attempt_no' => 1,
                    'method' => 'cash',
                    'amount' => Money::amount($amountCents),
                    'status' => 'succeeded',
                    'idempotency_key' => $key.':attempt',
                    'initiated_at' => $adjustment->updated_at ?? now(),
                    'started_at' => $adjustment->updated_at ?? now(),
                    'finished_at' => $adjustment->updated_at ?? now(),
                    'proof_type' => 'legacy_manual_confirmation',
                ]);

                RefundPayout::create([
                    'payout_no' => 'RP-'.Str::ulid(),
                    'obligation_id' => $obligation->id,
                    'order_id' => $order->id,
                    'attempt_id' => $attempt->id,
                    'payout_key' => $key.':attempt',
                    'amount' => Money::amount($amountCents),
                    'method' => 'cash',
                    'paid_at' => $adjustment->updated_at ?? now(),
                    // Historical reversal already happened: affects paid truth, but is NOT
                    // proven and must never decrement paid_amount a second time.
                    'verification_status' => 'legacy_unverified',
                    'affects_paid_amount' => true,
                    'paid_effect_applied_at' => null,
                    'recorded_on_cancelled_obligation' => false,
                    'proof_type' => 'legacy_manual_confirmation',
                    'migration_batch_id' => $batch->id,
                    'legacy_source' => 'order_item_adjustment:'.$adjustment->id,
                    'metadata' => ['backfill' => true],
                    'created_by' => $actor,
                ]);

                $audit->record(
                    'backfill.legacy_payout',
                    $order,
                    obligationId: $obligation->id,
                    eventKey: 'backfill_payout:'.$key,
                    payload: ['amount_cents' => $amountCents],
                );

                $stats['payouts_created']++;
            }

            foreach (ReturnItem::query()->where('order_item_id', $itemId)->where('refund_status', 'processed')->get() as $return) {
                $stats['legacy_processed_return_items']++;
                $key = 'legacy:return_item:'.$return->id;

                if (RefundObligation::query()->where('obligation_key', $key)->exists()) {
                    $stats['duplicate_keys']++;
                    continue;
                }

                $amountCents = Money::cents($return->refund_amount);
                if ($amountCents <= 0) {
                    continue;
                }

                $obligation = RefundObligation::create([
                    'obligation_no' => 'RO-'.Str::ulid(),
                    'order_id' => $order->id,
                    'source_type' => 'legacy',
                    'source_id' => $return->id,
                    'obligation_key' => $key,
                    'amount' => Money::amount($amountCents),
                    'status' => 'needs_verification',
                    'provenance' => 'legacy',
                    'reason' => 'Legacy processed return without a proof-backed ledger payout',
                    'deferred_amount' => '0.00',
                    'deferred_remaining' => '0.00',
                    'migration_batch_id' => $batch->id,
                    'legacy_source_type' => 'return_item',
                    'legacy_source_id' => $return->id,
                    'metadata' => ['backfill' => true, 'requires_evidence' => true],
                ]);

                $audit->record(
                    'backfill.needs_verification',
                    $order,
                    obligationId: $obligation->id,
                    eventKey: 'backfill_verification:'.$key,
                    payload: ['amount_cents' => $amountCents],
                );

                $stats['verification_created']++;
            }
        }

        // G is derived, never guessed: G = P + A (historical payouts that already left cash).
        $gross = Money::cents($order->paid_amount) + $this->legacyAppliedCents($order);

        if ($gross > 0) {
            $order->update(['gross_received_amount' => Money::amount($gross)]);
        }
    }

    private function legacyAppliedCents(Order $order): int
    {
        return Money::cents(RefundPayout::query()
            ->where('order_id', $order->id)
            ->where('affects_paid_amount', true)
            ->sum('amount'));
    }

    private function hasBillableUnits(Order $order): bool
    {
        return $order->items()->where('fulfilled_quantity', '>', 0)->exists();
    }

    private function emptyStats(): array
    {
        return [
            'orders_scanned' => 0,
            'snapshots_pending' => 0,
            'snapshots_created' => 0,
            'legacy_processed_adjustments' => 0,
            'payouts_pending' => 0,
            'payouts_created' => 0,
            'legacy_processed_return_items' => 0,
            'verification_pending' => 0,
            'verification_created' => 0,
            'duplicate_keys' => 0,
            'unallocatable_orders' => 0,
            'unexplained_cash' => 0,
            'gross_p_vs_g_minus_a' => 'ok',
        ];
    }

    private function render(array $stats, string $mode): void
    {
        $rows = [];
        foreach ($stats as $key => $value) {
            $rows[] = [$key, is_scalar($value) ? (string) $value : json_encode($value)];
        }

        $this->info("Refund ledger backfill ({$mode})");
        $this->table(['metric', 'value'], $rows);
    }
}
