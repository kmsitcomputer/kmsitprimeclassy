<?php

namespace App\Console\Commands;

use App\Models\BankTransferVerification;
use App\Models\CodPaymentProof;
use App\Models\Commission;
use App\Models\DeliveryVerification;
use App\Models\InventoryCancellationReversal;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductDiscount;
use App\Models\ProductVariation;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestProposal;
use App\Models\StockTransfer;
use App\Models\SubStockRequest;
use App\Models\SubStockReservation;
use App\Models\WarehouseStockRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Full Product domain reset — catalog and every row that exists because of it.
 *
 * HUMAN DECISION (LOCKED): `products:reset` removes ALL products together with
 * every relation that references them, INCLUDING warehouse transactions, stock
 * movements, stock requests and the related operational history. This
 * replaces the earlier policy in which warehouse history was a permanent
 * blocker. Master data and configuration outside the product domain are
 * preserved and verified.
 *
 * DEFAULT (no flags) is a read-only DRY RUN: zero database mutations. The
 * destructive path requires BOTH `--force` and `--backup-verified=<reference>`.
 *
 * ── Deletion boundary ──────────────────────────────────────────────────────
 * One rule, applied everywhere: a row goes if and only if it cannot exist
 * without the catalog.
 *
 * 1. Product-derived rows (mandatory target). `order_items.product_id` is
 *    NOT NULL, and every warehouse/stock target column describes the product
 *    being moved, so the whole row is a product relation. Deleted in full.
 * 2. Order-derived rows. An Order is deleted only when it had order items —
 *    every order item references the catalog. Its payments, shipments,
 *    additional payments, commissions, returns and cancellation reversals
 *    follow that exact order set (`by_order`), never a blanket table wipe,
 *    so an order without product lines survives untouched.
 * 3. Product-derived containers and document children follow their actual
 *    deleted parent rows. Order stock requests/proposals are selected by the
 *    deleted order/item scope; independent warehouse documents are product
 *    domain rows and are emptied with the catalog.
 * 4. Targeted promotions. Product/variation-targeted `vouchers` and
 *    `product_discounts` go; whole-catalog ones stay.
 * 5. SKU registry. Only `owner_type` in ('product','variant') rows of
 *    `catalog_skus`.
 * 6. Audit + evidence. `activity_logs` rows whose subject is a deleted
 *    record, media rows owned by a deleted model or held in a transaction
 *    evidence collection, plus the legacy proof/evidence paths. Gateway
 *    webhook audit is matched through its only available link —
 *    `(payment_method_id, gateway_reference)` against a deleted payment — so
 *    independent gateway history (unknown reference, spoofed delivery, or a
 *    payment whose order survives) is kept.
 *
 * ── Referenced by shared, non-product state ────────────────────────────────
 * Nothing. The command refuses to run if the schema contains any dependency
 * it has not mapped (see unmappedDependencies()), which is the reverse
 * direction: a PRESERVED table holding a foreign key into a deleted table, or
 * a table carrying `product_id` / `product_variation_id` that is not part of
 * the delete scope. A new migration that adds such a dependency stops the
 * reset instead of silently orphaning data.
 *
 * ── PRESERVE ───────────────────────────────────────────────────────────────
 * Every other table in the database, guarded by comparing counts before and
 * after `--force`. The set is DERIVED from the live schema, so users, roles,
 * user_closures, agent_profiles, konsumens and their addresses, product
 * categories, regions, languages, settings, CMS, media that is not
 * product/transaction owned, shipping/payment providers and every
 * configuration table, warehouse_settings, warehouse_sub_locations, Sheets
 * and OAuth configuration are protected without a hand-maintained list.
 * Infrastructure tables a live application may write during the run
 * (cache, sessions, jobs, …) are counted and reported but never enforced.
 *
 * Cart/wishlist: the backend owns no cart or wishlist table — both are
 * frontend-only Pinia/localStorage conveniences (pc-cart / pc-wishlist).
 *
 * Files: product image paths, product-owned media, transaction evidence
 * media and legacy proof/evidence paths are deleted ONLY AFTER the DB
 * transaction commits. A file failure is reported for the operator but never
 * rolls back (or corrupts) the committed reset; the command then exits
 * non-zero so the leftover bytes are not forgotten.
 *
 * STRICTLY FORBIDDEN and never used here: toggling the database's foreign
 * key enforcement pragma, raw whole-table truncation of product state,
 * destructive migration rollbacks or wipes, dropping tables, or deleting
 * master/configuration rows to satisfy a foreign key.
 */
class ResetProducts extends Command
{
    protected $signature = 'products:reset
        {--force : Execute the destructive reset}
        {--backup-verified= : Required with --force: reference to a verified database + media backup}';

    protected $description = 'Dry-run (default) or --force a FULL Product domain reset (catalog plus every product-derived transaction and warehouse row) while preserving all master data and configuration.';

    /**
     * Delete scope, child-before-parent. Every name is filtered by
     * Schema::hasTable() at runtime; absent tables are reported as skipped,
     * never assumed empty.
     *
     * Each key maps to a physical table (see stepTable()) and a scope
     * (see scopeQuery()), so the dry-run count and the executed DELETE are
     * produced by exactly the same query.
     *
     * @var list<string>
     */
    private const DELETE_STEPS = [
        // Audit selection must run before its subjects are deleted.
        'activity_logs',

        // ── A. product-derived operational history, child-before-parent ──
        'sub_stock_reservations',
        'stock_request_proposal_items',
        'stock_request_proposals',
        'stock_request_fulfillments',
        'inventory_cancellation_reversals',
        'delivery_verifications',
        'cod_payment_proofs',
        'bank_transfer_verifications',
        'payment_webhook_logs',
        'returns',
        'return_items',
        'order_item_adjustments',
        'order_fulfillment_change_proposals',
        'commissions',
        'stock_request_items',
        'stock_requests',
        'order_items',
        'sub_stock_request_items',
        'sub_stock_requests',
        'shipments',
        'payment_transactions',
        'order_additional_payments',
        'orders',
        'stock_handovers',
        'stock_transfer_items',
        'stock_transfers',
        'stock_movements',
        'stock_opname_items',
        'stock_opnames',
        'warehouse_stock_requests',
        'warehouse_migration_markers',
        'warehouse_migration_runs',
        // ── B. catalog domain, child-before-parent ──
        'product_variation_compositions',
        'product_variation_attribute_options_translations',
        'product_variation_attribute_options',
        'product_variation_attributes_translations',
        'product_variation_attributes',
        'products_translations',
        'product_attributes',
        'product_images',
        'media_product_owned',
        'media_transaction_evidence',
        'product_fees',
        'product_variation_fees',
        'product_discounts_product_targeted',
        'vouchers_product_targeted',
        'product_stocks',
        'product_variation_stocks',
        'warehouse_stocks_product_owned',
        'catalog_skus_product_owned',
        'product_variations',
        'products',
    ];

    /**
     * Tables emptied in full: every row is product-domain history (the
     * complete catalog/derived domain). Order-child rows are intentionally
     * partial and scoped via their real owner keys.
     *
     * @var list<string>
     */
    private const SCOPE_ALL = [
        'sub_stock_reservations',
        'order_items',
        'sub_stock_request_items',
        'sub_stock_requests',
        'stock_handovers',
        'stock_transfer_items',
        'stock_transfers',
        'stock_opname_items',
        'stock_opnames',
        'warehouse_stock_requests',
        'stock_movements',
        'warehouse_migration_markers',
        'warehouse_migration_runs',
        'product_variation_compositions',
        'product_variation_attribute_options_translations',
        'product_variation_attribute_options',
        'product_variation_attributes_translations',
        'product_variation_attributes',
        'products_translations',
        'product_attributes',
        'product_images',
        'product_fees',
        'product_variation_fees',
        'product_stocks',
        'product_variation_stocks',
        'product_variations',
        'products',
    ];

    /**
     * Rows deleted because they belong to an order that had product lines
     * (`by_order` scope) — the exact order set, never a blanket table wipe.
     *
     * @var list<string>
     */
    private const SCOPE_BY_ORDER = [
        'inventory_cancellation_reversals',
        'returns',
        'commissions',
        'shipments',
        'payment_transactions',
        'order_additional_payments',
        'stock_requests',
    ];

    /** Rows reached through exact deleted order item/return owners. */
    private const SCOPE_BY_ORDER_ITEM = [
        'return_items',
        'order_item_adjustments',
        'order_fulfillment_change_proposals',
    ];

    /** Child tables of a deleted shipment (`by_shipment` scope). */
    private const SCOPE_BY_SHIPMENT = ['delivery_verifications'];

    /** Child tables of a deleted payment transaction (`by_payment` scope). */
    private const SCOPE_BY_PAYMENT = ['cod_payment_proofs', 'bank_transfer_verifications'];

    /**
     * Gateway webhook audit rows that provably belong to a deleted payment.
     *
     * `handleWebhook()` resolves a transaction by
     * `(payment_method_id, gateway_reference)` — that is the only link the
     * table has. A log whose reference matches no transaction at all
     * (unknown reference, spoofed delivery, attack traffic) or matches a
     * payment of an order that survives has NO product relation and must not
     * be deleted with the catalog.
     */
    private const SCOPE_BY_PAYMENT_REFERENCE = ['payment_webhook_logs'];

    /** Targeted slices of shared tables: `product_targeted` scope. */
    private const SCOPE_PRODUCT_TARGETED = [
        'product_discounts_product_targeted',
        'vouchers_product_targeted',
        'warehouse_stocks_product_owned',
    ];

    /** @var list<string> */
    private const PRODUCT_MEDIABLE_TYPES = [
        'App\\Models\\Product',
        'App\\Models\\ProductVariation',
    ];

    /**
     * Media collections that exist only as evidence for an order/payment/
     * shipment/return record that is being deleted.
     *
     * @var list<string>
     */
    private const TRANSACTION_MEDIA_COLLECTIONS = [
        'bank_transfer_proof',
        'cod_payment_proof',
        'shipment_proof',
        'return_evidence',
    ];

    /**
     * Activity rows whose subject is a deleted record type. scopeQuery() also
     * constrains each type to the IDs actually in this reset's delete scope.
     *
     * @var list<string>
     */
    private const ACTIVITY_SUBJECT_TYPES = [
        Order::class,
        OrderItem::class,
        OrderItemAdjustment::class,
        OrderAdditionalPayment::class,
        PaymentTransaction::class,
        Shipment::class,
        DeliveryVerification::class,
        ReturnRequest::class,
        ReturnItem::class,
        Commission::class,
        StockRequest::class,
        StockRequestProposal::class,
        StockRequestFulfillment::class,
        InventoryCancellationReversal::class,
        StockMovement::class,
        StockTransfer::class,
        StockHandover::class,
        StockOpname::class,
        SubStockRequest::class,
        SubStockReservation::class,
        WarehouseStockRequest::class,
        BankTransferVerification::class,
        CodPaymentProof::class,
        Product::class,
        ProductVariation::class,
        ProductDiscount::class,
    ];

    /**
     * Shared tables: part deleted, part preserved. They are excluded from the
     * whole-table preservation guard (their total legitimately changes) and
     * are guarded cell by cell in sharedGuards().
     *
     * @var list<string>
     */
    private const SHARED_TABLES = [
        'media',
        'warehouse_stocks',
        'vouchers',
        'product_discounts',
        'catalog_skus',
    ];

    /**
     * Infrastructure tables a live application may legitimately write while
     * the reset runs. Counted and reported, never enforced.
     *
     * @var list<string>
     */
    private const RUNTIME_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'personal_access_tokens',
    ];

    public function handle(): int
    {
        $context = $this->buildContext();
        $plan = $this->buildPlan($context);
        $this->printDryRun($plan);

        if ($plan['unmapped'] !== []) {
            $this->error('REFUSED — UNMAPPED DEPENDENCY');
            foreach ($plan['unmapped'] as $dependency) {
                $this->line('Unmapped: '.$dependency);
            }
            $this->error('Every dependency must be mapped into the delete scope or proven independent before a destructive run.');

            return self::FAILURE;
        }

        if ($plan['preserved_tables'] === []) {
            $this->error('REFUSED — the preservation guard could not be derived from the schema.');

            return self::FAILURE;
        }

        $this->info('SAFE TO DELETE — preservation guard covers '.$plan['preserved_tables'].' table(s), no unmapped dependency.');

        if (! $this->option('force')) {
            $this->line('DRY RUN complete. No data was changed. Re-run with --force --backup-verified=<reference> to execute.');

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup-verified'));
        if ($backup === '') {
            $this->error('REFUSED — --force requires --backup-verified=<reference> to a verified database + media backup.');
            $this->error('Take and verify the backup first, then re-run with the reference. Nothing was changed.');

            return self::FAILURE;
        }

        $this->line('Verified backup reference: '.$backup);

        $preservedBefore = $this->counts($plan['preserved_table_names']);
        $runtimeBefore = $this->counts($this->existingTables(self::RUNTIME_TABLES));
        $sharedBefore = $this->sharedGuards($context);

        try {
            $files = null;
            DB::transaction(function () use (&$files, $context): void {
                $files = $this->deleteDomain($context);
            }, 3);
        } catch (\Throwable $e) {
            $this->error('Product reset failed and was rolled back. No data was changed.');
            $this->error('Reason: '.$e->getMessage());

            return self::FAILURE;
        }

        $fileFailures = $this->deleteFiles($files ?? []);

        $verification = $this->verifyReset(
            $preservedBefore,
            $runtimeBefore,
            $sharedBefore,
            $fileFailures
        );
        $this->printVerification($verification);

        if (! $verification['success']) {
            $this->error('Product reset verification FAILED. Review the reported counts.');

            return self::FAILURE;
        }

        $this->info('Product reset complete: catalog empty, product-derived history removed, master data intact.');

        return self::SUCCESS;
    }

    /**
     * Id sets the row-scoped deletes depend on, read from the live database.
     * Everything here is a read; nothing is written.
     *
     * `order_ids` is the set of orders that HAD order items. Every order item
     * carries a NOT NULL product reference, so those orders — and only those —
     * are product orders.
     *
     * @return array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}
     */
    private function buildContext(): array
    {
        $orderIds = [];
        if (Schema::hasTable('order_items')) {
            $orderIds = DB::table('order_items')->distinct()->pluck('order_id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $shipmentIds = [];
        if ($orderIds !== [] && Schema::hasTable('shipments')) {
            $shipmentIds = DB::table('shipments')->whereIn('order_id', $orderIds)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $paymentIds = [];
        if ($orderIds !== [] && Schema::hasTable('payment_transactions')) {
            $paymentIds = DB::table('payment_transactions')->whereIn('order_id', $orderIds)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $returnIds = [];
        if ($orderIds !== [] && Schema::hasTable('returns')) {
            $returnIds = DB::table('returns')->whereIn('order_id', $orderIds)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $productIds = Schema::hasTable('products')
            ? Product::withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $variationIds = Schema::hasTable('product_variations')
            ? ProductVariation::withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $codProofMediaIds = Schema::hasTable('cod_payment_proofs') && $paymentIds !== []
            ? DB::table('cod_payment_proofs')->whereIn('payment_transaction_id', $paymentIds)->pluck('proof_media_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'order_ids' => $orderIds,
            'shipment_ids' => $shipmentIds,
            'payment_ids' => $paymentIds,
            'return_ids' => $returnIds,
            'product_ids' => $productIds,
            'variation_ids' => $variationIds,
            'cod_proof_media_ids' => $codProofMediaIds,
        ];
    }

    /**
     * Inspect the live database and classify every table in the delete scope.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     * @return array{rows:array<string,int>, unmapped:list<string>, products:int, variations:int, preserved_tables:int, preserved_table_names:list<string>, backup:string}
     */
    private function buildPlan(array $context): array
    {
        $rows = [];
        foreach (self::DELETE_STEPS as $key) {
            $rows[$key] = $this->stepCount($key, $context);
        }

        $preserved = $this->preservedTables();

        return [
            'rows' => $rows,
            'unmapped' => $this->unmappedDependencies($context),
            'products' => (int) (Schema::hasTable('products') ? Product::withTrashed()->count() : 0),
            'variations' => (int) (Schema::hasTable('product_variations') ? ProductVariation::withTrashed()->count() : 0),
            'preserved_tables' => count($preserved),
            'preserved_table_names' => $preserved,
            'backup' => trim((string) $this->option('backup-verified')),
        ];
    }

    /** @param array<string,int> $plan */
    private function printDryRun(array $plan): void
    {
        $this->newLine();
        $this->info('FULL PRODUCT DOMAIN RESET — DRY RUN');
        $this->line('Environment: '.app()->environment());
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $this->line('Products (incl. trashed): '.$plan['products'].' | Variations (incl. trashed): '.$plan['variations']);
        $this->line('Cart/wishlist: no backend tables (frontend localStorage only) — nothing to delete server-side.');
        $this->line('Product-category pivots: none exist (products.category_id is a direct nullable FK) — categories are preserved.');

        $tableRows = [];
        foreach (self::DELETE_STEPS as $key) {
            $tableRows[] = [$this->displayStep($key), $plan['rows'][$key], 'DELETE'];
        }
        $this->table(['Table / scope', 'Rows', 'Action'], $tableRows);

        $this->line('Preservation guard: '.$plan['preserved_tables'].' table(s) derived from the live schema and compared before/after --force.');
        $this->line('Verified backup reference: '.($plan['backup'] !== '' ? $plan['backup'] : '(none supplied — --force will be refused)'));

        $skipped = $this->skippedSteps();
        if ($skipped !== []) {
            $this->line('Tables absent from this database (skipped): '.implode(', ', $skipped));
        }
    }

    /**
     * Execute every DELETE-scope deletion inside the caller's DB transaction.
     * Child rows before parents; FK checks stay enabled throughout.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     * @return array{product_images:list<string>, media:list<array{id:int,disk:string,path:string}>, legacy_paths:list<string>}
     */
    private function deleteDomain(array $context): array
    {
        // Fail closed: the schema must still be fully mapped at execution
        // time, not only at planning time.
        $unmapped = $this->unmappedDependencies($context);
        if ($unmapped !== []) {
            throw new RuntimeException('Unmapped dependency appeared: '.$unmapped[0].'.');
        }

        $files = [
            'product_images' => [],
            'media' => [],
            'legacy_paths' => [],
        ];

        $files['product_images'] = Schema::hasTable('product_images')
            ? DB::table('product_images')->pluck('path')->filter()->unique()->values()->all()
            : [];

        if (Schema::hasTable('media')) {
            $files['media'] = DB::table('media')
                ->where(function ($query) use ($context): void {
                    $query->where(function ($productMedia) use ($context): void {
                        $productMedia->where('mediable_type', Product::class)->whereIn('mediable_id', $context['product_ids']);
                    })->orWhere(function ($variationMedia) use ($context): void {
                        $variationMedia->where('mediable_type', ProductVariation::class)->whereIn('mediable_id', $context['variation_ids']);
                    })->orWhere(function ($evidence) use ($context): void {
                        $evidence->where('collection', 'shipment_proof')->where('mediable_type', Shipment::class)
                            ->whereIn('mediable_id', $context['shipment_ids']);
                    })->orWhere(function ($evidence) use ($context): void {
                        $evidence->where('collection', 'bank_transfer_proof')
                            ->where('mediable_type', PaymentTransaction::class)->whereIn('mediable_id', $context['payment_ids']);
                    })->orWhere(function ($evidence) use ($context): void {
                        $evidence->where('collection', 'cod_payment_proof')->whereIn('id', $context['cod_proof_media_ids']);
                    })->orWhere(function ($evidence) use ($context): void {
                        $evidence->where('collection', 'return_evidence')->where('mediable_type', ReturnRequest::class)
                            ->whereIn('mediable_id', $context['return_ids']);
                    });
                })
                ->get(['id', 'disk', 'path'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'disk' => (string) $row->disk, 'path' => (string) $row->path])
                ->all();
        }

        if (Schema::hasTable('bank_transfer_verifications')) {
            $files['legacy_paths'] = DB::table('bank_transfer_verifications')
                ->whereIn('payment_transaction_id', $context['payment_ids'])
                ->pluck('proof_image_path')
                ->filter()->unique()->values()->all();
        }

        if (Schema::hasTable('returns')) {
            $files['legacy_paths'] = array_values(array_unique(array_merge(
                $files['legacy_paths'],
                DB::table('returns')->whereIn('id', $context['return_ids'])
                    ->pluck('evidence_path')->filter()->all()
            )));
        }

        $this->assertScopeConsistency($context);
        $files = $this->excludeSharedEvidencePaths($files, $context);

        // order_items holds a RESTRICT self reference and two SET NULL links.
        // Every line is being removed anyway, so the links are broken for the
        // same atomic pass instead of depending on the statement's row order.
        $this->breakOrderItemCrossReferences($context);

        foreach (self::DELETE_STEPS as $key) {
            $this->deleteStep($key, $context);
        }

        // Fail closed: the roots must be gone before the transaction commits.
        if (Product::withTrashed()->count() !== 0 || ProductVariation::withTrashed()->count() !== 0) {
            throw new RuntimeException('Product roots were not fully removed.');
        }

        return $files;
    }

    /**
     * `order_items.split_from_order_item_id` is RESTRICT ON DELETE and
     * `shipment_id` / `additional_payment_id` are SET NULL; all rows go in this
     * reset, so the links are broken explicitly first.
     */
    private function breakOrderItemCrossReferences(array $context): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        $nulled = [];
        foreach (['split_from_order_item_id', 'shipment_id', 'additional_payment_id'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                $nulled[$column] = null;
            }
        }

        if ($nulled !== [] && $context['order_ids'] !== []) {
            DB::table('order_items')->whereIn('order_id', $context['order_ids'])->update($nulled);
        }
    }

    /**
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     */
    private function deleteStep(string $key, array $context): void
    {
        $query = $this->scopeQuery($key, $context);
        if ($query !== null) {
            $query->delete();
        }
    }

    /**
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     */
    private function stepCount(string $key, array $context): int
    {
        $query = $this->scopeQuery($key, $context);

        return $query === null ? 0 : (int) $query->count();
    }

    /**
     * The single source of truth for both the dry-run count and the executed
     * DELETE, so the plan can never disagree with the action.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     */
    private function scopeQuery(string $key, array $context): ?Builder
    {
        $table = $this->stepTable($key);
        if (! Schema::hasTable($table)) {
            return null;
        }

        if (in_array($key, self::SCOPE_PRODUCT_TARGETED, true)) {
            return DB::table($table)
                ->whereNotNull('product_id')
                ->orWhereNotNull('product_variation_id');
        }

        if ($key === 'catalog_skus_product_owned') {
            return DB::table($table)->whereIn('owner_type', ['product', 'variant']);
        }

        if ($key === 'media_product_owned') {
            if ($context['product_ids'] === [] && $context['variation_ids'] === []) {
                return DB::table($table)->whereRaw('1 = 0');
            }

            return DB::table($table)->where(function ($query) use ($context): void {
                $query->where(fn ($q) => $q->where('mediable_type', Product::class)->whereIn('mediable_id', $context['product_ids']))
                    ->orWhere(fn ($q) => $q->where('mediable_type', ProductVariation::class)->whereIn('mediable_id', $context['variation_ids']));
            });
        }

        if ($key === 'media_transaction_evidence') {
            if ($context['shipment_ids'] === [] && $context['payment_ids'] === [] && $context['return_ids'] === []) {
                return DB::table($table)->whereRaw('1 = 0');
            }

            // Both owner identity and collection must match the deleted record set.
            return DB::table($table)->where(function ($query) use ($context): void {
                $query->where(fn ($q) => $q->where('collection', 'shipment_proof')->where('mediable_type', Shipment::class)->whereIn('mediable_id', $context['shipment_ids']))
                    ->orWhere(fn ($q) => $q->where('collection', 'bank_transfer_proof')->where('mediable_type', PaymentTransaction::class)->whereIn('mediable_id', $context['payment_ids']))
                    ->orWhere(fn ($q) => $q->where('collection', 'cod_payment_proof')->whereIn('id', $context['cod_proof_media_ids']))
                    ->orWhere(fn ($q) => $q->where('collection', 'return_evidence')->where('mediable_type', ReturnRequest::class)->whereIn('mediable_id', $context['return_ids']));
            });
        }

        if ($key === 'activity_logs') {
            $hasDeleteSubjects = false;
            foreach (self::ACTIVITY_SUBJECT_TYPES as $subjectType) {
                if ($this->subjectIdsForType($subjectType, $context) !== []) {
                    $hasDeleteSubjects = true;
                    break;
                }
            }

            if (! $hasDeleteSubjects) {
                return DB::table($table)->whereRaw('1 = 0');
            }

            return DB::table($table)->where(function ($query) use ($context): void {
                foreach (self::ACTIVITY_SUBJECT_TYPES as $subjectType) {
                    $ids = $this->subjectIdsForType($subjectType, $context);
                    if ($ids !== []) {
                        $query->orWhere(function ($typed) use ($subjectType, $ids): void {
                            $typed->where('subject_type', $subjectType)->whereIn('subject_id', $ids);
                        });
                    }
                }
            });
        }

        if ($key === 'orders') {
            // Only orders that actually had product lines.
            return DB::table($table)->whereIn('id', $context['order_ids']);
        }

        if (in_array($key, self::SCOPE_BY_ORDER_ITEM, true)) {
            return $this->orderItemOwnedQuery($table, $context);
        }

        if (in_array($key, self::SCOPE_BY_SHIPMENT, true)) {
            return $context['shipment_ids'] === []
                ? DB::table($table)->whereRaw('1 = 0')
                : DB::table($table)->whereIn('shipment_id', $context['shipment_ids']);
        }

        if (in_array($key, self::SCOPE_BY_PAYMENT, true)) {
            return $context['payment_ids'] === []
                ? DB::table($table)->whereRaw('1 = 0')
                : DB::table($table)->whereIn('payment_transaction_id', $context['payment_ids']);
        }

        if (in_array($key, self::SCOPE_BY_PAYMENT_REFERENCE, true)) {
            if ($context['order_ids'] === [] || ! Schema::hasTable('payment_transactions')) {
                return DB::table($table)->whereRaw('1 = 0');
            }

            return DB::table($table)->whereExists(function ($sub) use ($context): void {
                $sub->select(DB::raw(1))
                    ->from('payment_transactions as deleted_payments')
                    ->whereColumn('deleted_payments.gateway_reference', 'payment_webhook_logs.gateway_reference')
                    ->whereColumn('deleted_payments.payment_method_id', 'payment_webhook_logs.payment_method_id')
                    ->whereIn('deleted_payments.order_id', $context['order_ids']);
            });
        }

        if (in_array($key, self::SCOPE_BY_ORDER, true)) {
            return $context['order_ids'] === []
                ? DB::table($table)->whereRaw('1 = 0')
                : DB::table($table)->whereIn('order_id', $context['order_ids']);
        }

        if ($key === 'stock_request_items') {
            return DB::table($table)->whereIn('order_item_id', function ($query) use ($context): void {
                $query->select('id')->from('order_items')->whereIn('order_id', $context['order_ids']);
            });
        }

        if (in_array($key, ['stock_request_proposals', 'stock_request_fulfillments'], true)) {
            return DB::table($table)->whereIn('stock_request_id', function ($query) use ($context): void {
                $query->select('id')->from('stock_requests')->whereIn('order_id', $context['order_ids']);
            });
        }

        if ($key === 'stock_request_proposal_items') {
            return DB::table($table)->whereIn('stock_request_proposal_id', function ($query) use ($context): void {
                $query->select('id')->from('stock_request_proposals')->whereIn('stock_request_id', function ($requests) use ($context): void {
                    $requests->select('id')->from('stock_requests')->whereIn('order_id', $context['order_ids']);
                });
            });
        }

        if (in_array($key, self::SCOPE_ALL, true)) {
            return DB::table($table);
        }

        throw new RuntimeException("Delete step {$key} has no defined scope.");
    }

    /**
     * Scope adjustment/proposal rows through actual deleted order-item and/or
     * order references. A present table with no recognized owner key cannot
     * safely be reset, so refuse before any mutation.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     */
    private function orderItemOwnedQuery(string $table, array $context): Builder
    {
        $hasOrderItem = Schema::hasColumn($table, 'order_item_id');
        $hasOrder = Schema::hasColumn($table, 'order_id');

        if (! $hasOrderItem && ! $hasOrder) {
            throw new RuntimeException("Unmapped deletion scope: {$table} has no recognized order_item_id or order_id owner column.");
        }

        $query = DB::table($table);
        if ($hasOrderItem) {
            $query->whereIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_id', $context['order_ids']));
        }
        if ($hasOrder) {
            $query->whereIn('order_id', $context['order_ids']);
        }

        return $query;
    }

    /**
     * Keep physical bytes whenever a retained database reference shares them.
     * All owner tables that can identify evidence are enumerated; legacy paths
     * are public-disk references. Unknown extra collection owners are protected
     * by the media-row path check.
     *
     * @param  array{product_images:list<string>, media:list<array{id:int,disk:string,path:string}>, legacy_paths:list<string>}  $files
     * @param  array<string,mixed>  $context
     * @return array{product_images:list<string>, media:list<array{id:int,disk:string,path:string}>, legacy_paths:list<string>}
     */
    private function excludeSharedEvidencePaths(array $files, array $context): array
    {
        $mediaDeleteIds = array_values(array_unique(array_column($files['media'], 'id')));
        $shared = [];
        $candidates = [];
        foreach ($files['product_images'] as $path) {
            $candidates['public|'.$path] = ['public', $path];
        }
        foreach ($files['media'] as $file) {
            $disk = $file['disk'] !== '' ? $file['disk'] : 'public';
            $candidates[$disk.'|'.$file['path']] = [$disk, $file['path']];
        }
        foreach ($files['legacy_paths'] as $path) {
            $candidates['public|'.$path] = ['public', $path];
        }

        foreach ($candidates as $key => [$disk, $path]) {
            if ($this->physicalPathHasRetainedReference($disk, $path, $mediaDeleteIds, $context)) {
                $shared[$key] = true;
            }
        }

        $files['product_images'] = array_values(array_filter($files['product_images'], fn (string $path) => ! isset($shared['public|'.$path])));
        $files['media'] = array_values(array_filter($files['media'], function (array $file) use ($shared): bool {
            $disk = $file['disk'] !== '' ? $file['disk'] : 'public';

            return ! isset($shared[$disk.'|'.$file['path']]);
        }));
        $files['legacy_paths'] = array_values(array_filter($files['legacy_paths'], fn (string $path) => ! isset($shared['public|'.$path])));

        return $files;
    }

    /** @param array<string, mixed> $context */
    private function physicalPathHasRetainedReference(string $disk, string $path, array $mediaDeleteIds, array $context): bool
    {
        if (Schema::hasTable('media') && DB::table('media')->where('path', $path)
            ->where(function ($query) use ($disk): void {
                $query->where('disk', $disk);
                if ($disk === 'public') {
                    $query->orWhereNull('disk')->orWhere('disk', '');
                }
            })->whereNotIn('id', $mediaDeleteIds)->exists()) {
            return true;
        }

        if ($disk !== 'public') {
            return false;
        }

        return (Schema::hasTable('product_images') && DB::table('product_images')->where('path', $path)->whereNotIn('product_id', $context['product_ids'])->exists())
            || (Schema::hasTable('bank_transfer_verifications') && DB::table('bank_transfer_verifications')->where('proof_image_path', $path)->whereNotIn('payment_transaction_id', $context['payment_ids'])->exists())
            || (Schema::hasTable('returns') && DB::table('returns')->where('evidence_path', $path)->whereNotIn('id', $context['return_ids'])->exists())
            || (Schema::hasTable('shipments') && Schema::hasTable('media') && DB::table('shipments as s')->join('media as m', 'm.id', '=', 's.proof_media_id')->whereNotIn('s.id', $context['shipment_ids'])->where('m.path', $path)->exists())
            || (Schema::hasTable('cod_payment_proofs') && Schema::hasTable('payment_transactions') && Schema::hasTable('media') && DB::table('cod_payment_proofs as cp')->join('payment_transactions as pt', 'pt.id', '=', 'cp.payment_transaction_id')->join('media as m', 'm.id', '=', 'cp.proof_media_id')->whereNotIn('pt.id', $context['payment_ids'])->where('m.path', $path)->exists());
    }

    /**
     * Fail closed when retained/deleted order boundary rows are inconsistent
     * or ambiguous before any data is mutated.
     *
     * @param  array<string,mixed>  $context
     */
    private function assertScopeConsistency(array $context): void
    {
        if (Schema::hasTable('media')) {
            $this->assertSelectedMediaSafe($context);
        }
        if (Schema::hasTable('return_items') && $context['return_ids'] !== []) {
            $badReturnChild = DB::table('return_items')->whereIn('return_id', $context['return_ids'])
                ->whereNotIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_id', $context['order_ids']))
                ->exists();
            $retainedReturnChild = DB::table('return_items')->whereNotIn('return_id', $context['return_ids'])
                ->whereIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_id', $context['order_ids']))
                ->exists();
            if ($badReturnChild || $retainedReturnChild) {
                throw new RuntimeException('Unmapped dependency: return_items crosses the product-order deletion boundary. No data was changed.');
            }
        }

        foreach (['order_item_adjustments', 'order_fulfillment_change_proposals'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $hasOrderItem = Schema::hasColumn($table, 'order_item_id');
            $hasOrder = Schema::hasColumn($table, 'order_id');
            if (! $hasOrderItem && ! $hasOrder) {
                throw new RuntimeException("Unmapped deletion scope: {$table} has no recognized owner column. No data was changed.");
            }
            if ($hasOrderItem && $hasOrder && $context['order_ids'] !== []) {
                $crossOwned = DB::table($table)
                    ->whereIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_id', $context['order_ids']))
                    ->whereNotIn('order_id', $context['order_ids'])->exists();
                if ($crossOwned) {
                    throw new RuntimeException("Unmapped dependency: {$table} row crosses retained order and deleted item ownership. No data was changed.");
                }
            }
        }
    }

    /**
     * Refuse when evidence media the reset intends to delete is still the FK
     * target of a retained payment/shipment, or when a retained media row
     * shares the same path with a deleted media row (physical bytes kept).
     *
     * @param  array<string,mixed>  $context
     */
    private function assertSelectedMediaSafe(array $context): void
    {
        $selected = DB::table('media')->where(function ($query) use ($context): void {
            $query->where(fn ($q) => $q->where('mediable_type', Product::class)->whereIn('mediable_id', $context['product_ids']))
                ->orWhere(fn ($q) => $q->where('mediable_type', ProductVariation::class)->whereIn('mediable_id', $context['variation_ids']))
                ->orWhere(fn ($q) => $q->where('collection', 'shipment_proof')->where('mediable_type', Shipment::class)->whereIn('mediable_id', $context['shipment_ids']))
                ->orWhere(fn ($q) => $q->where('collection', 'bank_transfer_proof')->where('mediable_type', PaymentTransaction::class)->whereIn('mediable_id', $context['payment_ids']))
                ->orWhere(fn ($q) => $q->where('collection', 'cod_payment_proof')->whereIn('id', $context['cod_proof_media_ids']))
                ->orWhere(fn ($q) => $q->where('collection', 'return_evidence')->where('mediable_type', ReturnRequest::class)->whereIn('mediable_id', $context['return_ids']));
        })->get(['id', 'disk', 'path']);

        $mediaDeleteIds = $selected->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($mediaDeleteIds === []) {
            return;
        }

        $retainedRef = Schema::hasTable('shipments') && DB::table('shipments')
            ->whereNotIn('id', $context['shipment_ids'])->whereIn('proof_media_id', $mediaDeleteIds)->exists();
        if ($retainedRef) {
            throw new RuntimeException('Unmapped dependency: selected evidence media is still owned by a retained shipment. No data was changed.');
        }

        if (Schema::hasTable('cod_payment_proofs') && Schema::hasTable('payment_transactions')) {
            $retainedCodRef = DB::table('cod_payment_proofs as cp')
                ->join('payment_transactions as pt', 'pt.id', '=', 'cp.payment_transaction_id')
                ->whereNotIn('pt.id', $context['payment_ids'])
                ->whereIn('cp.proof_media_id', $mediaDeleteIds)->exists();
            if ($retainedCodRef) {
                throw new RuntimeException('Unmapped dependency: selected evidence media is still owned by a retained payment. No data was changed.');
            }
        }
    }

    /** @param array<string, mixed> $context @return list<int> */
    private function subjectIdsForType(string $subjectType, array $context): array
    {
        $step = match ($subjectType) {
            Order::class => 'orders',
            OrderItem::class => 'order_items',
            OrderItemAdjustment::class => 'order_item_adjustments',
            OrderAdditionalPayment::class => 'order_additional_payments',
            PaymentTransaction::class => 'payment_transactions',
            Shipment::class => 'shipments',
            DeliveryVerification::class => 'delivery_verifications',
            ReturnRequest::class => 'returns',
            ReturnItem::class => 'return_items',
            Commission::class => 'commissions',
            StockRequest::class => 'stock_requests',
            StockRequestProposal::class => 'stock_request_proposals',
            StockRequestFulfillment::class => 'stock_request_fulfillments',
            InventoryCancellationReversal::class => 'inventory_cancellation_reversals',
            StockMovement::class => 'stock_movements',
            StockTransfer::class => 'stock_transfers',
            StockHandover::class => 'stock_handovers',
            StockOpname::class => 'stock_opnames',
            SubStockRequest::class => 'sub_stock_requests',
            SubStockReservation::class => 'sub_stock_reservations',
            WarehouseStockRequest::class => 'warehouse_stock_requests',
            BankTransferVerification::class => 'bank_transfer_verifications',
            CodPaymentProof::class => 'cod_payment_proofs',
            Product::class => 'products',
            ProductVariation::class => 'product_variations',
            ProductDiscount::class => 'product_discounts_product_targeted',
            default => null,
        };

        if ($step === null) {
            return [];
        }

        if (in_array($subjectType, [BankTransferVerification::class, CodPaymentProof::class], true)) {
            $table = $subjectType === BankTransferVerification::class ? 'bank_transfer_verifications' : 'cod_payment_proofs';

            return Schema::hasTable($table)
                ? DB::table($table)->whereIn('payment_transaction_id', $context['payment_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        $query = $this->scopeQuery($step, $context);

        return $query === null ? [] : $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function stepTable(string $key): string
    {
        return match ($key) {
            'media_product_owned', 'media_transaction_evidence' => 'media',
            'product_discounts_product_targeted' => 'product_discounts',
            'vouchers_product_targeted' => 'vouchers',
            'warehouse_stocks_product_owned' => 'warehouse_stocks',
            'catalog_skus_product_owned' => 'catalog_skus',
            default => $key,
        };
    }

    private function displayStep(string $key): string
    {
        return match ($key) {
            'media_product_owned' => 'media (product-owned)',
            'media_transaction_evidence' => 'media (transaction evidence)',
            'product_discounts_product_targeted' => 'product_discounts (product-targeted)',
            'vouchers_product_targeted' => 'vouchers (product-targeted)',
            'warehouse_stocks_product_owned' => 'warehouse_stocks (product-targeted)',
            'catalog_skus_product_owned' => 'catalog_skus (product/variant-owned)',
            default => $key,
        };
    }

    /** @return list<string> */
    private function skippedSteps(): array
    {
        return array_values(array_filter(
            self::DELETE_STEPS,
            fn (string $key) => ! Schema::hasTable($this->stepTable($key))
        ));
    }

    /**
     * Dependencies this command has NOT mapped. A non-empty result means the
     * schema holds a relation the delete scope does not cover, so the
     * destructive run is refused instead of orphaning or silently rewriting
     * preserved data.
     *
     * Four checks:
     * 1. a PRESERVED table holding a foreign key into a table this command
     *    EMPTIES COMPLETELY — RESTRICT/NO ACTION would abort the delete, SET
     *    NULL would rewrite a preserved row, CASCADE would remove rows the
     *    plan never reported;
     * 2. a PRESERVED row holding a foreign key into a record that falls inside
     *    a PARTIAL delete scope (a product-owned media row, a whole-catalog
     *    promotion, an order of a product order). Rows outside the slice are
     *    fine — e.g. a user avatar in the media table is untouched — but a
     *    preserved row pointing INTO the slice is unmapped;
     * 3. any table carrying a product target column that is not part of the
     *    delete scope at all;
     * 4. a RETAINED order (one this command keeps — it never had product lines)
     *    whose `orders.voucher_id` points at a product-/variation-targeted
     *    voucher this command deletes. `orders.voucher_id` is a plain index,
     *    not a foreign key, so the FK-based check (1) cannot see it and a
     *    preserved order would silently dangle over a deleted voucher.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     * @return list<string>
     */
    private function unmappedDependencies(array $context): array
    {
        $unmapped = [];

        foreach ($this->foreignKeysIntoDeleteScope() as $constraint) {
            $parent = $constraint['parent'];
            $steps = array_values(array_filter(self::DELETE_STEPS, fn (string $step): bool => $this->stepTable($step) === $parent));
            if ($steps === []) {
                continue;
            }
            foreach ($steps as $step) {
                $slice = $this->scopeQuery($step, $context);
                if ($slice === null) {
                    continue;
                }
                if (DB::table($constraint['table'])->whereIn($constraint['column'], $slice->select($this->primaryKeyOf($parent)))->exists()) {
                    $unmapped[] = sprintf(
                        '%s.%s -> %s (delete rule %s): a preserved row references a record inside the %s delete scope',
                        $constraint['table'],
                        $constraint['column'],
                        $parent,
                        $constraint['delete_rule'],
                        $this->displayStep($step)
                    );

                    break;
                }
            }
        }

        foreach ($this->productTargetColumns() as $table => $columns) {
            $unmapped[] = $table.' ('.implode(', ', $columns).'): carries a product target outside the delete scope';
        }

        $voucherReference = $this->voucherAttributionReference($context);
        if ($voucherReference !== '') {
            $unmapped[] = $voucherReference;
        }

        return $unmapped;
    }

    /**
     * A retained order referencing a deleted product-targeted voucher, or an
     * empty string when no such reference exists.
     *
     * The reverse direction (a product-order row whose voucher is deleted WITH
     * that order) is fine — the order goes too. Only a RETAINED order keeps a
     * dangling attribution.
     *
     * @param  array<string,mixed>  $context
     */
    private function voucherAttributionReference(array $context): string
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('vouchers') || ! Schema::hasColumn('orders', 'voucher_id')) {
            return '';
        }

        $targetedVoucherIds = DB::table('vouchers')
            ->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('product_variation_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($targetedVoucherIds === []) {
            return '';
        }

        $deletedOrderIds = $context['order_ids'];
        $retainedDangling = DB::table('orders')
            ->whereIn('voucher_id', $targetedVoucherIds)
            ->when($deletedOrderIds !== [], fn ($q) => $q->whereNotIn('id', $deletedOrderIds))
            ->limit(1)
            ->exists();

        if (! $retainedDangling) {
            // The only orders using a product-targeted voucher are the ones this
            // reset deletes; the attribution dies with its record, which is the
            // locked product-reset rule, not a dangling reference.
            return '';
        }

        return 'orders.voucher_id: a RETAINED order (one without product lines) still points at a product/variation-targeted voucher being deleted by this run. The voucher attribution would dangle; resolve it, or the run stays refused.';
    }

    /**
     * Physical tables removed in full.
     *
     * @return list<string>
     */
    private function emptiedPhysicalTables(): array
    {
        $tables = [];
        foreach (self::SCOPE_ALL as $step) {
            $tables[] = $this->stepTable($step);
        }

        return array_values(array_unique($tables));
    }

    /**
     * Steps that delete only part of their table, keyed by physical table.
     * Everything in DELETE_STEPS that is not in SCOPE_ALL qualifies; a table
     * can carry several slices (media is emptied by two of them).
     *
     * @return array<string, list<string>>
     */
    private function slicedSteps(): array
    {
        $steps = [];
        foreach (self::DELETE_STEPS as $step) {
            if (! in_array($step, self::SCOPE_ALL, true)) {
                $steps[$this->stepTable($step)][] = $step;
            }
        }

        return $steps;
    }

    private function primaryKeyOf(string $table): string
    {
        return Schema::hasColumn($table, 'id') ? 'id' : 'sku';
    }

    /** @return list<array{table:string,column:string,parent:string,delete_rule:string}> */
    private function foreignKeysIntoDeleteScope(): array
    {
        $deleteTables = $this->deletedPhysicalTables();

        $rows = $this->schemaIntrospect(implode(' ', [
            'SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column,',
            'r.REFERENCED_TABLE_NAME AS parent_table, r.DELETE_RULE AS delete_rule',
            'FROM information_schema.REFERENTIAL_CONSTRAINTS r',
            'JOIN information_schema.KEY_COLUMN_USAGE k',
            'ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA',
            'AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME',
            'WHERE r.CONSTRAINT_SCHEMA = ?',
        ]));

        $found = [];
        foreach ($rows as $row) {
            $parent = (string) $row->parent_table;
            $table = (string) $row->child_table;

            if (! in_array($parent, $deleteTables, true) || in_array($table, $deleteTables, true)) {
                continue;
            }

            $found[] = [
                'table' => $table,
                'column' => (string) $row->child_column,
                'parent' => $parent,
                'delete_rule' => (string) $row->delete_rule,
            ];
        }

        return $found;
    }

    /**
     * Every table that stores a product or variation target, keyed by table.
     *
     * @return array<string, list<string>>
     */
    private function productTargetColumns(): array
    {
        $deleteTables = $this->deletedPhysicalTables();
        $found = [];

        foreach ($this->schemaIntrospect(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name'
            .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?'
        ) as $row) {
            $table = (string) $row->table_name;
            $column = (string) $row->column_name;

            if (in_array($table, $deleteTables, true)) {
                continue;
            }

            if (preg_match('/^(product|variation|product_variation)_id$/', $column) === 1) {
                $found[$table][] = $column;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Physical tables this command removes rows from or empties outright.
     *
     * @return list<string>
     */
    private function deletedPhysicalTables(): array
    {
        $tables = [];
        foreach (self::DELETE_STEPS as $key) {
            $tables[] = $this->stepTable($key);
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<stdClass>
     */
    private function schemaIntrospect(string $sql): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return DB::select($sql, [DB::connection()->getDatabaseName()]);
    }

    /**
     * Preserved slices of shared tables: rows that must be identical before
     * and after the reset.
     *
     * @param  array{order_ids:list<int>, shipment_ids:list<int>, payment_ids:list<int>, return_ids:list<int>, product_ids:list<int>, variation_ids:list<int>, cod_proof_media_ids:list<int>}  $context
     * @return array<string,int>
     */
    private function sharedGuards(array $context): array
    {
        $guards = [];

        if (Schema::hasTable('warehouse_stocks')) {
            $guards['warehouse_stocks (rows without a product target)'] = (int) DB::table('warehouse_stocks')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        if (Schema::hasTable('media')) {
            $total = (int) DB::table('media')->count();
            $productOwned = $this->stepCount('media_product_owned', $context);
            $transactionEvidence = $this->stepCount('media_transaction_evidence', $context);
            $guards['media (rows outside delete scope)'] = $total - $productOwned - $transactionEvidence;
        }

        if (Schema::hasTable('vouchers')) {
            $guards['vouchers (whole-catalog rows)'] = (int) DB::table('vouchers')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        if (Schema::hasTable('product_discounts')) {
            $guards['product_discounts (untargeted rows)'] = (int) DB::table('product_discounts')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        if (Schema::hasTable('catalog_skus')) {
            $guards['catalog_skus (non product/variant owners)'] = (int) DB::table('catalog_skus')
                ->whereNotIn('owner_type', ['product', 'variant'])->count();
        }

        if (Schema::hasTable('payment_webhook_logs')) {
            // Total minus the rows the delete scope removes. Before the reset
            // this is the independent gateway audit history; after the reset it
            // is everything that survived, so the two must be equal.
            $total = (int) DB::table('payment_webhook_logs')->count();
            $guards['payment_webhook_logs (rows with no deleted payment behind them)'] = $total - $this->stepCount('payment_webhook_logs', $context);
        }

        return $guards;
    }

    /**
     * Physical product files are removed ONLY after the DB transaction has
     * committed. A file failure is reported for the operator; it never rolls
     * back or corrupts the committed database reset.
     *
     * Paths come from database rows, so every path is validated before any
     * filesystem call: only plain relative paths inside the disk root are ever
     * deleted. Anything else (absolute paths, `..` segments, stream wrappers,
     * null bytes) is skipped and reported so the verification refuses to
     * declare success on ambiguous input.
     *
     * @param  array{product_images:list<string>, media:list<array{id:int,disk:string,path:string}>, legacy_paths:list<string>}  $files
     * @return list<string>
     */
    private function deleteFiles(array $files): array
    {
        $failures = [];

        foreach ($files['product_images'] as $path) {
            $failures = array_merge($failures, $this->deleteFile('public', $path));
        }

        foreach ($files['legacy_paths'] as $path) {
            $failures = array_merge($failures, $this->deleteFile('public', $path));
        }

        foreach ($files['media'] as $file) {
            $disk = $file['disk'] !== '' ? $file['disk'] : 'public';
            $failures = array_merge($failures, $this->deleteFile($disk, $file['path']));
        }

        return array_values(array_unique($failures));
    }

    /** @return list<string> */
    private function deleteFile(string $disk, string $path): array
    {
        if (! self::isSafeRelativePath($path)) {
            return ['skipped-unsafe:'.$disk.':'.$path];
        }

        try {
            if (Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->delete($path)) {
                return [$disk.':'.$path];
            }
        } catch (\Throwable $e) {
            return [$disk.':'.$path];
        }

        return [];
    }

    /**
     * A storage path is deletable only when it is a plain relative path that
     * cannot escape the disk root.
     */
    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/')) {
            return false;
        }

        if ((bool) preg_match('#^[a-zA-Z0-9][a-zA-Z0-9+.-]*://#', $normalized)) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,int>  $preservedBefore
     * @param  array<string,int>  $runtimeBefore
     * @param  array<string,int>  $sharedBefore
     * @param  list<string>  $fileFailures
     * @return array<string,mixed>
     */
    private function verifyReset(array $preservedBefore, array $runtimeBefore, array $sharedBefore, array $fileFailures): array
    {
        $context = $this->buildContext();

        $deleteAfter = [];
        foreach (self::DELETE_STEPS as $key) {
            $deleteAfter[$key] = $this->stepCount($key, $context);
        }
        $notEmpty = array_filter($deleteAfter, fn (int $count) => $count !== 0);

        $preservedAfter = $this->counts($this->preservedTables());
        $changed = [];
        foreach ($preservedBefore as $table => $count) {
            if (($preservedAfter[$table] ?? null) !== $count) {
                $changed[$table] = $preservedAfter[$table] ?? -1;
            }
        }

        $sharedAfter = $this->sharedGuards($context);
        $sharedChanged = [];
        foreach ($sharedBefore as $key => $count) {
            if (($sharedAfter[$key] ?? null) !== $count) {
                $sharedChanged[$key] = $sharedAfter[$key] ?? -1;
            }
        }

        // Reported, never enforced: a live application may write these.
        $runtimeAfter = $this->counts($this->existingTables(self::RUNTIME_TABLES));
        $runtimeChanged = [];
        foreach ($runtimeBefore as $table => $count) {
            if (($runtimeAfter[$table] ?? null) !== $count) {
                $runtimeChanged[$table] = $runtimeAfter[$table] ?? -1;
            }
        }

        $success = $notEmpty === []
            && $changed === []
            && $sharedChanged === []
            && $fileFailures === []
            && Product::withTrashed()->count() === 0
            && ProductVariation::withTrashed()->count() === 0;

        return [
            'success' => $success,
            'delete_after' => $deleteAfter,
            'changed' => $changed,
            'preserved_before' => $preservedBefore,
            'shared_changed' => $sharedChanged,
            'runtime_before' => $runtimeBefore,
            'runtime_changed' => $runtimeChanged,
            'not_empty' => $notEmpty,
            'file_failures' => $fileFailures,
        ];
    }

    /** @param array<string,mixed> $verification */
    private function printVerification(array $verification): void
    {
        $this->newLine();
        $this->info('PRODUCT RESET VERIFICATION');
        $this->line('Products (incl. trashed): '.Product::withTrashed()->count());
        $this->line('Variations (incl. trashed): '.ProductVariation::withTrashed()->count());
        $this->line('Preserved-domain tables checked: '.count($verification['preserved_before']));

        $rows = [];
        foreach (self::DELETE_STEPS as $key) {
            $rows[] = [$this->displayStep($key), $verification['delete_after'][$key]];
        }
        $this->table(['Delete scope', 'Rows after'], $rows);

        if ($verification['changed'] !== [] || $verification['shared_changed'] !== []) {
            foreach ($verification['changed'] as $table => $after) {
                $before = $verification['preserved_before'][$table] ?? '?';
                $this->line("PRESERVED CHANGED: {$table} before={$before} after={$after}");
            }
            foreach ($verification['shared_changed'] as $key => $after) {
                $this->line("PRESERVED SHARED CHANGED: {$key} after={$after}");
            }
        } else {
            $this->line('Preserved-domain counts unchanged.');
        }

        foreach ($verification['runtime_changed'] as $table => $after) {
            $before = $verification['runtime_before'][$table] ?? '?';
            $this->line("RUNTIME TABLE CHANGED (not enforced): {$table} before={$before} after={$after}");
        }

        $this->line('File deletion failures: '.count($verification['file_failures']));
        foreach ($verification['file_failures'] as $failure) {
            $this->line('File failure: '.$failure);
        }
    }

    /**
     * Every table whose rows must be identical before and after --force,
     * derived from the live schema: everything except a table in the delete
     * scope, a shared cell-guarded table, or a runtime table.
     *
     * Deriving it is what keeps the guarantee true across migrations — a new
     * table is protected the day it is created.
     *
     * @return list<string>
     */
    private function preservedTables(): array
    {
        $excluded = array_values(array_unique(array_merge(
            $this->deletedPhysicalTables(),
            self::SHARED_TABLES,
            self::RUNTIME_TABLES
        )));

        $tables = [];
        foreach ($this->allTables() as $table) {
            if (! in_array($table, $excluded, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Base tables of the current database, through the framework's own schema
     * builder so the derivation stays driver-agnostic.
     *
     * @return list<string>
     */
    private function allTables(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        sort($tables);

        return $tables;
    }

    /** @param list<string> $tables @return array<string,int> */
    private function counts(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => (int) DB::table($table)->count()])
            ->all();
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function existingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table) => Schema::hasTable($table)));
    }
}
