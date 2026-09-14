<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ResetTransactions extends Command
{
    protected $signature = 'transactions:reset
        {--dry-run : Show the impact without changing data}
        {--force : Skip confirmation; required in production}';

    protected $description = 'Safely remove transaction-derived data while preserving users, catalog, hierarchy, configuration, and current stock.';

    /** @var list<string> */
    private const TRANSACTION_TABLES = [
        'return_items',
        'returns',
        'order_item_adjustments',
        'commissions',
        'cod_payment_proofs',
        'bank_transfer_verifications',
        'order_additional_payments',
        'payment_transactions',
        'order_items',
        'shipments',
        'payment_webhook_logs',
        'orders',
    ];

    /** @var list<string> */
    private const MASTER_TABLES = [
        'users', 'roles', 'user_closures', 'products', 'product_variations',
        'catalog_skus', 'product_fees', 'product_variation_fees', 'provinces',
        'regencies', 'districts', 'villages', 'payment_methods',
        'shipping_providers', 'settings', 'sheets_destinations', 'sheets_configs',
    ];

    /** @var list<string> */
    private const TRANSACTION_MEDIA_COLLECTIONS = [
        'bank_transfer_proof', 'cod_payment_proof', 'shipment_proof', 'return_evidence',
    ];

    /** Dedicated directories contain transaction evidence only, including legacy files without media rows. */
    private const TRANSACTION_MEDIA_DIRECTORIES = [
        'payments/bank-transfer-proofs', 'returns/evidence',
        'media/shipment_proof', 'media/cod_payment_proof',
    ];

    public function handle(): int
    {
        if (! $this->option('dry-run') && app()->environment('production') && ! $this->option('force')) {
            $this->error('Production reset requires the explicit --force option.');

            return self::FAILURE;
        }

        $before = $this->counts(self::TRANSACTION_TABLES);
        $masterBefore = $this->counts(self::MASTER_TABLES);
        $inventory = $this->inventoryImpact();
        $media = $this->transactionMedia();
        $legacyPaths = DB::table('bank_transfer_verifications')->pluck('proof_image_path')->filter()->unique()->values()->all();
        $activityCount = $this->transactionActivityQuery()->count();
        $sheetLogCount = DB::table('sheets_sync_logs')->count();

        $this->newLine();
        $this->info('TRANSACTION RESET AUDIT');
        $this->line('Environment: '.app()->environment());
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $this->table(['Table', 'Rows to delete'], collect($before)->map(fn (int $count, string $table) => [$table, $count])->values()->all());
        $this->line("Transaction activity logs: {$activityCount}");
        $this->line("Google Sheets sync logs: {$sheetLogCount}");
        $this->line('Referenced transaction media: '.$media->count());
        $this->line('Legacy bank-transfer proof paths: '.count($legacyPaths));
        $this->line('Transaction stock movements: '.$inventory['movement_count']);
        $this->line('Product stock rows with reservations: '.$inventory['product_reserved_rows']);
        $this->line('Variant stock rows with reservations: '.$inventory['variation_reserved_rows']);
        $this->line('On-hand restoration delta: '.$inventory['on_hand_delta']);

        if ($inventory['unsafe_rows'] > 0) {
            $this->error('BLOCKER: transaction stock history contains unsupported movement types. No data was changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN complete. No data was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete all audited transaction data now?', false)) {
            $this->warn('Reset cancelled.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($inventory): void {
            $this->restoreInventory($inventory['rows']);

            foreach (self::TRANSACTION_TABLES as $table) {
                DB::table($table)->delete();
            }

            $this->transactionStockMovementQuery()->delete();
            $this->transactionActivityQuery()->delete();
            DB::table('sheets_sync_logs')->delete();
            $this->clearDatabaseTransactionCache();
        }, 3);

        $fileFailures = $this->deleteTransactionFiles($media, $legacyPaths);
        Cache::forget('dashboard.metrics');
        Cache::forget('reports.summary');

        $after = $this->counts(self::TRANSACTION_TABLES);
        $masterAfter = $this->counts(self::MASTER_TABLES);
        $remainingTransactionMovements = $this->transactionStockMovementQuery()->count();
        $remainingReservations = DB::table('product_stocks')->where('quantity_reserved', '>', 0)->count()
            + DB::table('product_variation_stocks')->where('quantity_reserved', '>', 0)->count();
        $masterChanged = collect($masterBefore)->filter(fn (int $count, string $table) => $masterAfter[$table] !== $count);
        $notEmpty = collect($after)->filter(fn (int $count) => $count !== 0);

        $this->newLine();
        $this->info('TRANSACTION RESET VERIFICATION');
        $this->table(['Table', 'Before', 'After'], collect($before)->map(fn (int $count, string $table) => [$table, $count, $after[$table]])->values()->all());
        $this->line("Remaining transaction stock movements: {$remainingTransactionMovements}");
        $this->line("Remaining reserved-stock rows: {$remainingReservations}");
        $this->line('Transaction file deletion failures: '.count($fileFailures));

        if ($notEmpty->isNotEmpty() || $remainingTransactionMovements !== 0 || $remainingReservations !== 0 || $masterChanged->isNotEmpty() || $fileFailures !== []) {
            $this->error('Reset verification failed. Review the reported counts.');

            return self::FAILURE;
        }

        $this->info('All transaction data is empty and all audited master row counts are preserved.');

        return self::SUCCESS;
    }

    /** @param list<string> $tables @return array<string,int> */
    private function counts(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function transactionStockMovementQuery()
    {
        return DB::table('stock_movements')->where(function ($query): void {
            $query->whereIn('reference_type', ['order', 'order_item_adjustment'])
                ->orWhere('note', 'return_restock');
        });
    }

    /** @return array{movement_count:int,product_reserved_rows:int,variation_reserved_rows:int,on_hand_delta:int,unsafe_rows:int,rows:Collection} */
    private function inventoryImpact(): array
    {
        $rows = $this->transactionStockMovementQuery()->orderBy('id')->get();
        $onHandTypes = ['in', 'out', 'adjustment', 'transfer_in', 'transfer_out', 'return_restock'];
        $unsafe = $rows->filter(fn ($row) => ! in_array($row->type, array_merge($onHandTypes, ['reserve', 'release']), true))->count();

        return [
            'movement_count' => $rows->count(),
            'product_reserved_rows' => DB::table('product_stocks')->where('quantity_reserved', '>', 0)->count(),
            'variation_reserved_rows' => DB::table('product_variation_stocks')->where('quantity_reserved', '>', 0)->count(),
            'on_hand_delta' => (int) $rows->whereIn('type', $onHandTypes)->sum('quantity'),
            'unsafe_rows' => $unsafe,
            'rows' => $rows,
        ];
    }

    private function restoreInventory($movements): void
    {
        $onHandTypes = ['in', 'out', 'adjustment', 'transfer_in', 'transfer_out', 'return_restock'];

        foreach ($movements->whereIn('type', $onHandTypes)->groupBy(fn ($row) => implode(':', [$row->agent_id, $row->product_id ?? 'v', $row->product_variation_id ?? 'p'])) as $group) {
            $first = $group->first();
            $table = $first->product_id !== null ? 'product_stocks' : 'product_variation_stocks';
            $targetColumn = $first->product_id !== null ? 'product_id' : 'product_variation_id';
            $targetId = $first->{$targetColumn};
            $stock = DB::table($table)->where('agent_id', $first->agent_id)->where($targetColumn, $targetId)->lockForUpdate()->first();

            if (! $stock) {
                throw new RuntimeException("Missing {$table} row for transaction stock restoration.");
            }

            $restored = $stock->quantity_on_hand - (int) $group->sum('quantity');
            if ($restored < 0) {
                throw new RuntimeException("Stock restoration would make {$table}.quantity_on_hand negative.");
            }
            DB::table($table)->where('id', $stock->id)->update(['quantity_on_hand' => $restored]);
        }

        // Reserved quantity has no master meaning; it exists only for active orders.
        DB::table('product_stocks')->where('quantity_reserved', '!=', 0)->update(['quantity_reserved' => 0]);
        DB::table('product_variation_stocks')->where('quantity_reserved', '!=', 0)->update(['quantity_reserved' => 0]);
    }

    private function transactionActivityQuery()
    {
        $subjectTypes = [
            'App\\Models\\Order', 'App\\Models\\OrderItem', 'App\\Models\\OrderItemAdjustment',
            'App\\Models\\PaymentTransaction', 'App\\Models\\Shipment', 'App\\Models\\ReturnRequest',
            'App\\Models\\ReturnItem', 'App\\Models\\Commission',
        ];

        return DB::table('activity_logs')->where(function ($query) use ($subjectTypes): void {
            $query->whereIn('subject_type', $subjectTypes)
                ->orWhere('event', 'like', 'order.%')
                ->orWhere('event', 'like', 'payment.%')
                ->orWhere('event', 'like', 'shipment.%')
                ->orWhere('event', 'like', 'return.%')
                ->orWhere('event', 'like', 'commission.%')
                ->orWhere('event', 'like', 'order_item_adjustment.%');
        });
    }

    private function transactionMedia()
    {
        return Media::query()->whereIn('collection', self::TRANSACTION_MEDIA_COLLECTIONS)->get();
    }

    private function deleteTransactionFiles($media, array $legacyPaths): array
    {
        $failures = [];
        foreach ($media as $item) {
            if (! Storage::disk($item->disk)->delete($item->path)) {
                $failures[] = $item->path;

                continue;
            }
            $item->delete();
        }
        foreach ($legacyPaths as $path) {
            if (Storage::disk('public')->exists($path) && ! Storage::disk('public')->delete($path)) {
                $failures[] = $path;
            }
        }
        foreach (self::TRANSACTION_MEDIA_DIRECTORIES as $directory) {
            if (Storage::disk('public')->exists($directory) && ! Storage::disk('public')->deleteDirectory($directory)) {
                $failures[] = $directory;
            }
        }

        return $failures;
    }

    private function clearDatabaseTransactionCache(): void
    {
        DB::table('cache')->where(function ($query): void {
            foreach (['order', 'transaction', 'payment', 'refund', 'commission', 'dashboard', 'report', 'shipment'] as $word) {
                $query->orWhere('key', 'like', "%{$word}%");
            }
        })->delete();
        DB::table('cache_locks')->where(function ($query): void {
            foreach (['order', 'transaction', 'payment', 'refund', 'commission', 'dashboard', 'report', 'shipment'] as $word) {
                $query->orWhere('key', 'like', "%{$word}%");
            }
        })->delete();
    }
}
