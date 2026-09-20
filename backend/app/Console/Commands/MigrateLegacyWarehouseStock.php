<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MigrateLegacyWarehouseStock extends Command
{
    protected $signature = 'warehouse:migrate-legacy-stock
        {--dry-run : Scan and report without writing any database quantity or marker}
        {--agent= : Restrict the scan to one Agent id}
        {--batch-size=100 : Number of source rows per batch}
        {--run-id= : Resume or reuse an existing migration run id}';

    protected $description = 'Backfill legacy Product and Variation on-hand into warehouse Transit with durable markers.';

    private array $summary = [
        'rows_scanned' => 0, 'rows_migrated' => 0, 'rows_skipped' => 0,
        'rows_conflicted' => 0, 'rows_failed' => 0, 'quantity_total' => 0,
    ];

    public function handle(): int
    {
        $requiredTables = ['product_stocks', 'product_variation_stocks', 'warehouse_stocks', 'warehouse_migration_runs', 'warehouse_migration_markers'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table) => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            $this->error('BLOCKED: required warehouse schema is not migrated: '.implode(', ', $missingTables));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $runId = (string) ($this->option('run-id') ?: 'legacy-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)));
        $batchSize = max(1, (int) $this->option('batch-size'));
        $agentId = $this->option('agent') ? (int) $this->option('agent') : null;

        if ($agentId !== null && ! DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->where('u.id', $agentId)->where('r.slug', 'agen')->exists()) {
            $this->error("Invalid Agent id: {$agentId}");

            return self::FAILURE;
        }

        $run = null;
        if (! $dryRun) {
            $run = DB::table('warehouse_migration_runs')->where('run_id', $runId)->first();
            if (! $run) {
                DB::table('warehouse_migration_runs')->insert(['run_id' => $runId, 'dry_run' => false, 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($run->status === 'completed') {
                $this->info("Migration run {$runId} is already completed; nothing to do.");

                return self::SUCCESS;
            }
        }

        $this->scanProductStocks($runId, $dryRun, $agentId, $batchSize);
        $this->scanVariationStocks($runId, $dryRun, $agentId, $batchSize);

        if (! $dryRun) {
            DB::table('warehouse_migration_runs')->where('run_id', $runId)->update([
                ...$this->summary, 'status' => $this->summary['rows_conflicted'] || $this->summary['rows_failed'] ? 'blocked' : 'completed',
                'completed_at' => now(), 'updated_at' => now(), 'summary' => json_encode($this->summary),
            ]);
        }

        $this->table(['Metric', 'Value'], collect($this->summary)->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->line('run_id='.$runId.' dry_run='.($dryRun ? '1' : '0'));

        return ($this->summary['rows_conflicted'] || $this->summary['rows_failed']) ? self::FAILURE : self::SUCCESS;
    }

    private function scanProductStocks(string $runId, bool $dryRun, ?int $agentId, int $batchSize): void
    {
        DB::table('product_stocks')->when($agentId, fn ($q) => $q->where('agent_id', $agentId))->orderBy('id')->chunkById($batchSize, function ($rows) use ($runId, $dryRun) {
            foreach ($rows as $row) {
                $this->migrateRow($runId, $dryRun, 'product_stock', $row->id, (int) $row->agent_id, (int) $row->product_id, null, (int) $row->quantity_on_hand);
            }
        });
    }

    private function scanVariationStocks(string $runId, bool $dryRun, ?int $agentId, int $batchSize): void
    {
        DB::table('product_variation_stocks')->when($agentId, fn ($q) => $q->where('agent_id', $agentId))->orderBy('id')->chunkById($batchSize, function ($rows) use ($runId, $dryRun) {
            foreach ($rows as $row) {
                $this->migrateRow($runId, $dryRun, 'product_variation_stock', $row->id, (int) $row->agent_id, null, (int) $row->product_variation_id, (int) $row->quantity_on_hand);
            }
        });
    }

    private function migrateRow(string $runId, bool $dryRun, string $sourceType, int $sourceId, int $agentId, ?int $productId, ?int $variationId, int $quantity): void
    {
        $this->summary['rows_scanned']++;
        $this->summary['quantity_total'] += max(0, $quantity);
        $error = $this->validateRow($agentId, $productId, $variationId, $quantity);
        if ($error) {
            $this->record($runId, $dryRun, compact('sourceType', 'sourceId', 'agentId', 'productId', 'variationId', 'quantity'), 'conflict', $error);

            return;
        }
        if ($dryRun) {
            $exists = DB::table('warehouse_stocks')->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id')->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))->exists();
            if ($exists) {
                $this->summary['rows_conflicted']++;
            } else {
                $this->summary['rows_migrated']++;
            }

            return;
        }
        DB::transaction(function () use ($runId, $sourceType, $sourceId, $agentId, $productId, $variationId, $quantity) {
            if (DB::table('warehouse_migration_markers')->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'migrated')->exists()) {
                $this->summary['rows_skipped']++;

                return;
            }
            $query = DB::table('warehouse_stocks')->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id')->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))->lockForUpdate();
            if ($query->exists()) {
                $this->record($runId, false, compact('sourceType', 'sourceId', 'agentId', 'productId', 'variationId', 'quantity'), 'conflict', 'warehouse_target_exists');

                return;
            }
            $warehouseId = DB::table('warehouse_stocks')->insertGetId(['agent_id' => $agentId, 'product_id' => $productId, 'product_variation_id' => $variationId, 'stock_type' => 'transit', 'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]);
            if ($quantity > 0) {
                DB::table('stock_movements')->insert(['agent_id' => $agentId, 'product_id' => $productId, 'product_variation_id' => $variationId, 'type' => 'legacy_backfill', 'quantity' => $quantity, 'stock_type' => 'transit', 'reference_type' => 'warehouse_migration_run', 'reference_id' => $warehouseId, 'note' => $runId, 'created_by' => null, 'created_at' => now()]);
            }
            $this->record($runId, false, compact('sourceType', 'sourceId', 'agentId', 'productId', 'variationId', 'quantity'), 'migrated', null);
        });
    }

    private function validateRow(int $agentId, ?int $productId, ?int $variationId, int $quantity): ?string
    {
        if ($quantity < 0) {
            return 'negative_quantity';
        }
        if (($productId === null) === ($variationId === null)) {
            return 'target_xor_invalid';
        }
        if (! DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->where('u.id', $agentId)->where('r.slug', 'agen')->exists()) {
            return 'missing_agent';
        }
        if ($productId !== null && ! DB::table('products')->where('id', $productId)->exists()) {
            return 'missing_product';
        }
        if ($variationId !== null && ! DB::table('product_variations')->where('id', $variationId)->whereNull('deleted_at')->exists()) {
            return 'missing_variation';
        }

        return null;
    }

    private function record(string $runId, bool $dryRun, array $row, string $status, ?string $error): void
    {
        if ($status === 'migrated') {
            $this->summary['rows_migrated']++;
        }
        if ($status === 'conflict') {
            $this->summary['rows_conflicted']++;
        }
        if ($error) {
            $this->summary['rows_failed']++;
        }
        if (! $dryRun) {
            DB::table('warehouse_migration_markers')->insertOrIgnore(['run_id' => DB::table('warehouse_migration_runs')->where('run_id', $runId)->value('id'), 'source_type' => $row['sourceType'], 'source_id' => $row['sourceId'], 'agent_id' => $row['agentId'], 'product_id' => $row['productId'], 'product_variation_id' => $row['variationId'], 'source_quantity' => $row['quantity'], 'destination_stock_type' => 'transit', 'status' => $status, 'error_code' => $error, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
