<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Production-safe full Product + Product Variation domain reset.
 *
 * DEFAULT (no flags) is a read-only DRY RUN: zero database mutations.
 * Actual deletion happens only with the explicit --force flag, and only
 * when no preserved historical/business row still references the catalog.
 *
 * Domain classification, derived from the actual migrations + FK graph
 * (RECON ONCE; see the class constants below):
 *
 * DELETE (lifecycle genuinely owned by Product / ProductVariation):
 * - product_variation_compositions, product_variation_attribute_options
 *   (+ translations), product_variation_attributes (+ translations),
 *   product_attributes, products_translations, product_images,
 *   product_fees, product_variation_fees, catalog_skus rows owned by the
 *   deleted products/variations, product-targeted product_discounts and
 *   product-/variation-targeted vouchers, product_stocks,
 *   product_variation_stocks, product-referencing warehouse_stocks rows,
 *   media rows whose mediable is a Product/ProductVariation, and finally
 *   product_variations + products themselves (including soft-deleted rows).
 *
 * DETACH: none. Every preserved reference to products/variations is either
 * a nullable FK whose business/history semantics forbid detachment, or a
 * NOT NULL RESTRICT FK that cannot be detached without a schema change.
 * No schema is altered to manufacture nullability.
 *
 * PRESERVE (never touched): users, roles, user_closures, agent_profiles,
 * customers (konsumen users), orders, order_items, shipments, payments and
 * payment transactions, commissions, invoices/configs, returns, regions,
 * CMS, website/media-library settings, installer state, activity_logs,
 * shipping/payment/Google OAuth configuration, product_categories and all
 * warehouse workflow history (stock_requests family, transfers, handovers,
 * opnames, sub-stock requests, stock_movements, migration markers).
 * Whole-catalog (untargeted) vouchers are preserved as well.
 *
 * BLOCKING (a preserved row references Product/ProductVariation, so the
 * destructive run is refused instead of deleting around it):
 * - order_items (product_id NOT NULL + RESTRICT; snapshots do not replace
 *   the FK, and the schema offers no nullable detach path)
 * - stock_movements, stock_request_items, sub_stock_reservations,
 *   sub_stock_request_items, stock_transfer_items, warehouse_stock_requests,
 *   stock_opname_items, warehouse_migration_markers (all RESTRICT)
 * - orders.voucher_id pointing at a product-targeted voucher (nullable but
 *   historical attribution; deleting the voucher would dangle history)
 *
 * Cart/wishlist: the PrimeClassy backend owns no cart or wishlist tables —
 * both are frontend-only Pinia/localStorage conveniences (pc-cart /
 * pc-wishlist keys). There is nothing to delete server-side; the command
 * reports them as absent/skipped.
 *
 * Product media: product_images rows store a plain `public`-disk path (no
 * media-table linkage); exclusively product-owned media-table rows (morph
 * mediable to Product/ProductVariation) are handled the same way. File
 * bytes are deleted ONLY AFTER the DB transaction commits; a file failure
 * is reported but never rolls back (or corrupts) the committed reset.
 *
 * STRICTLY FORBIDDEN and never used here: toggling the database's foreign
 * key enforcement pragma, raw whole-table truncation, destructive
 * migration rollbacks or wipes, dropping tables, deleting
 * orders/payments/shipments/commissions to satisfy a foreign key.
 */
class ResetProducts extends Command
{
    protected $signature = 'products:reset
        {--force : Execute the destructive reset (refused when blockers exist)}';

    protected $description = 'Dry-run (default) or --force a full Product + Variation domain reset while preserving all unrelated data.';

    /**
     * Product-owned tables deleted child-before-parent. Every name is
     * filtered by Schema::hasTable() at runtime; absent tables are reported
     * as skipped, never assumed empty.
     *
     * @var list<string>
     */
    private const DELETE_TABLES_IN_ORDER = [
        'product_variation_compositions',
        'product_variation_attribute_options_translations',
        'product_variation_attribute_options',
        'product_variation_attributes_translations',
        'product_variation_attributes',
        'products_translations',
        'product_attributes',
        'product_images',
        'media_product_owned',
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
     * Preserved tables whose restricted FK to products/variations BLOCKS
     * --force while any referencing row exists. Key = table, value = the
     * [product column, variation column] pair used for the reference check.
     *
     * @var array<string, array{0: string|null, 1: string|null}>
     */
    private const BLOCKING_REFERENCES = [
        'order_items' => ['product_id', 'product_variation_id'],
        'stock_movements' => ['product_id', 'product_variation_id'],
        'stock_request_items' => ['product_id', 'product_variation_id'],
        'sub_stock_reservations' => ['product_id', 'product_variation_id'],
        'sub_stock_request_items' => ['product_id', 'product_variation_id'],
        'stock_transfer_items' => ['product_id', 'product_variation_id'],
        'warehouse_stock_requests' => ['product_id', 'product_variation_id'],
        'stock_opname_items' => ['product_id', 'product_variation_id'],
        'warehouse_migration_markers' => ['product_id', 'product_variation_id'],
    ];

    /**
     * Representative preserved-domain tables compared before/after --force.
     * Any unexpected change fails verification.
     *
     * warehouse_stocks, media, vouchers and product_discounts are shared
     * tables (part product-owned, part preserved) and are guarded cell by
     * cell in sharedGuards() instead of by whole-table counts.
     *
     * @var list<string>
     */
    private const PRESERVED_TABLES = [
        'users',
        'roles',
        'permissions',
        'user_closures',
        'agent_profiles',
        'product_categories',
        'orders',
        'order_items',
        'shipments',
        'payment_transactions',
        'payment_methods',
        'commissions',
        'returns',
        'return_items',
        'stock_requests',
        'stock_movements',
        'warehouse_settings',
        'warehouse_sub_locations',
        'provinces',
        'regencies',
        'districts',
        'villages',
        'cms_pages',
        'cms_articles',
        'cms_homepage_blocks',
        'settings',
        'languages',
        'activity_logs',
        'agent_payment_gateway_configs',
        'agent_shipping_provider_configs',
        'agent_payment_method_settings',
        'agent_shipping_provider_settings',
        'google_auth_settings',
        'agent_google_auth_configs',
        'shipping_providers',
        'shipping_configurations',
        'invoice_configs',
    ];

    /** @var list<string> */
    private const PRODUCT_MEDIABLE_TYPES = [
        'App\\Models\\Product',
        'App\\Models\\ProductVariation',
    ];

    public function handle(): int
    {
        $plan = $this->buildPlan();
        $this->printDryRun($plan);

        if ($plan['blocked']) {
            $this->error('BLOCKED — PRESERVED DATA REFERENCES PRODUCTS');

            return self::FAILURE;
        }

        $this->info('SAFE TO DELETE');

        if (! $this->option('force')) {
            $this->line('DRY RUN complete. No data was changed. Re-run with --force to execute.');

            return self::SUCCESS;
        }

        $preservedBefore = $this->counts($this->existingTables(self::PRESERVED_TABLES));
        $sharedBefore = $this->sharedGuards();

        try {
            $files = null;
            DB::transaction(function () use (&$files): void {
                $files = $this->deleteDomainTables();
            }, 3);
        } catch (\Throwable $e) {
            $this->error('Product reset failed and was rolled back. No data was changed.');
            $this->error('Reason: '.$e->getMessage());

            return self::FAILURE;
        }

        $fileFailures = $this->deleteProductFiles($files ?? []);

        $verification = $this->verifyReset($preservedBefore, $sharedBefore, $fileFailures);
        $this->printVerification($verification);

        if (! $verification['success']) {
            $this->error('Product reset verification FAILED. Review the reported counts.');

            return self::FAILURE;
        }

        $this->info('Product reset complete: catalog is empty and preserved data is intact.');

        return self::SUCCESS;
    }

    /**
     * Inspect the live database and classify every product-domain table.
     *
     * @return array{
     *   products:int, variations:int, rows:array<string,int>, actions:array<string,string>,
     *   blockers:array<string,int>, skipped:array<int,string>, blocked:bool,
     *   product_image_files:int, product_media_files:int
     * }
     */
    private function buildPlan(): array
    {
        $productIds = $this->existingProductIds();
        $variationIds = $this->existingVariationIds();

        $rows = [];
        $actions = [];
        foreach (self::DELETE_TABLES_IN_ORDER as $key) {
            $rows[$key] = $this->deleteScopeCount($key, $productIds, $variationIds);
            $actions[$key] = 'DELETE';
        }

        $blockers = [];
        foreach (self::BLOCKING_REFERENCES as $table => [$productColumn, $variationColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $count = $this->referencingCount($table, $productColumn, $variationColumn, $productIds, $variationIds);
            if ($count > 0) {
                $blockers[$table] = $count;
            }
        }

        $voucherBlockers = $this->voucherAttributionBlockers($productIds, $variationIds);
        foreach ($voucherBlockers as $table => $count) {
            $blockers[$table] = $count;
        }

        $skipped = array_values(array_filter(
            array_merge(self::DELETE_TABLES_IN_ORDER, array_keys(self::BLOCKING_REFERENCES)),
            fn (string $table) => ! $this->tableExistsForKey($table)
        ));

        return [
            'products' => count($productIds),
            'variations' => count($variationIds),
            'rows' => $rows,
            'actions' => $actions,
            'blockers' => $blockers,
            'skipped' => $skipped,
            'blocked' => $blockers !== [],
            'product_image_files' => $this->productImageFileCount(),
            'product_media_files' => $this->productMediaFileCount(),
        ];
    }

    private function printDryRun(array $plan): void
    {
        $this->newLine();
        $this->info('PRODUCT RESET DRY RUN');
        $this->line('Environment: '.app()->environment());
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $this->line('Products: '.$plan['products'].' | Variations: '.$plan['variations']);
        $this->line('Cart/wishlist: no backend tables (frontend localStorage only) — nothing to delete server-side.');
        $this->line('Product-category pivots: none exist (products.category_id is a direct nullable FK) — categories are preserved.');

        $tableRows = [];
        foreach (self::DELETE_TABLES_IN_ORDER as $key) {
            $tableRows[] = [$this->displayTable($key), $plan['rows'][$key], $plan['actions'][$key]];
        }
        foreach ($plan['blockers'] as $table => $count) {
            $tableRows[] = [$table, $count, 'BLOCK'];
        }
        $this->table(['Table', 'Current row count', 'Action'], $tableRows);

        $this->line('Product image files: '.$plan['product_image_files']);
        $this->line('Product-owned media files: '.$plan['product_media_files']);

        if ($plan['skipped'] !== []) {
            $this->line('Tables absent from this database (skipped): '.implode(', ', $plan['skipped']));
        }

        if ($plan['blockers'] !== []) {
            foreach ($plan['blockers'] as $table => $count) {
                $this->line("Blocking dependency: {$table} holds {$count} preserved row(s) referencing products/variations.");
            }
        }
    }

    /**
     * Execute every DELETE-scope deletion inside the caller's DB transaction.
     * Child rows before parents; FK checks stay enabled throughout.
     *
     * @return array{product_images:list<string>, media:list<array{disk:string,path:string}>}
     */
    private function deleteDomainTables(): array
    {
        $productIds = $this->existingProductIds();
        $variationIds = $this->existingVariationIds();

        // Safety re-check inside the transaction: a preserved reference that
        // appeared after the dry-run read must abort instead of violating a
        // RESTRICT foreign key halfway through the plan.
        $this->assertNoBlockers($productIds, $variationIds);

        $imagePaths = Schema::hasTable('product_images')
            ? DB::table('product_images')->pluck('path')->filter()->unique()->values()->all()
            : [];

        $mediaFiles = Schema::hasTable('media')
            ? DB::table('media')->whereIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES)
                ->get(['disk', 'path'])
                ->map(fn ($row) => ['disk' => (string) $row->disk, 'path' => (string) $row->path])
                ->all()
            : [];

        $this->deleteVariationGraphs($variationIds);
        $this->deleteProductGraphs($productIds);

        if (Schema::hasTable('product_discounts')) {
            DB::table('product_discounts')
                ->whereNotNull('product_id')
                ->orWhereNotNull('product_variation_id')
                ->delete();
        }

        if (Schema::hasTable('vouchers')) {
            DB::table('vouchers')
                ->whereNotNull('product_id')
                ->orWhereNotNull('product_variation_id')
                ->delete();
        }

        $this->deleteCurrentStocks($productIds, $variationIds);
        $this->deleteCatalogSkus();

        // Roots last, including soft-deleted rows, so the final state holds
        // zero product/variation rows rather than zero visible ones. Deleted
        // one model at a time (chunked) so Eloquent lifecycle hooks stay in
        // play; child tables were already cleared explicitly above, and DB
        // cascades cover anything declarative.
        ProductVariation::withTrashed()->when($variationIds !== [], fn ($q) => $q->whereIn('id', $variationIds))
            ->chunkById(500, fn ($rows) => $rows->each->forceDelete());
        Product::withTrashed()->when($productIds !== [], fn ($q) => $q->whereIn('id', $productIds))
            ->chunkById(500, fn ($rows) => $rows->each->forceDelete());

        // Fail closed: the roots must be gone before the transaction commits.
        if (Product::withTrashed()->count() !== 0 || ProductVariation::withTrashed()->count() !== 0) {
            throw new RuntimeException('Product roots were not fully removed.');
        }

        return ['product_images' => $imagePaths, 'media' => $mediaFiles];
    }

    /**
     * When the id list is empty the `when()` constraints below are skipped on
     * purpose: an empty catalog means every remaining child row is orphaned
     * product state (e.g. from a restored backup) and is still in scope.
     */
    private function deleteVariationGraphs(array $variationIds): void
    {
        if (Schema::hasTable('product_variation_compositions')) {
            DB::table('product_variation_compositions')
                ->when($variationIds !== [], fn ($q) => $q->whereIn('product_variation_id', $variationIds))
                ->delete();
        }

        if (Schema::hasTable('product_variation_attribute_options_translations')) {
            DB::table('product_variation_attribute_options_translations')
                ->when($variationIds !== [], function ($q) use ($variationIds): void {
                    $q->whereIn('product_variation_attribute_option_id', function ($sub) use ($variationIds): void {
                        $sub->select('product_variation_attribute_options.id')
                            ->from('product_variation_attribute_options')
                            ->join(
                                'product_variation_attributes',
                                'product_variation_attributes.id',
                                '=',
                                'product_variation_attribute_options.product_variation_attribute_id'
                            )
                            ->join('products', 'products.id', '=', 'product_variation_attributes.product_id')
                            ->whereIn('products.id', $this->existingProductIds());
                    });
                })
                ->delete();
        }

        if (Schema::hasTable('product_images')) {
            DB::table('product_images')
                ->when($variationIds !== [], fn ($q) => $q->whereIn('product_variation_id', $variationIds))
                ->delete();
        }

        if (Schema::hasTable('product_variation_fees')) {
            DB::table('product_variation_fees')
                ->when($variationIds !== [], fn ($q) => $q->whereIn('product_variation_id', $variationIds))
                ->delete();
        }

        if (Schema::hasTable('product_variation_stocks')) {
            DB::table('product_variation_stocks')
                ->when($variationIds !== [], fn ($q) => $q->whereIn('product_variation_id', $variationIds))
                ->delete();
        }
    }

    private function deleteProductGraphs(array $productIds): void
    {
        if (Schema::hasTable('product_variation_attribute_options')) {
            DB::table('product_variation_attribute_options')
                ->when($productIds !== [], function ($q) use ($productIds): void {
                    $q->whereIn('product_variation_attribute_id', function ($sub) use ($productIds): void {
                        $sub->select('id')->from('product_variation_attributes')->whereIn('product_id', $productIds);
                    });
                })
                ->delete();
        }

        if (Schema::hasTable('product_variation_attributes_translations')) {
            DB::table('product_variation_attributes_translations')
                ->when($productIds !== [], function ($q) use ($productIds): void {
                    $q->whereIn('product_variation_attribute_id', function ($sub) use ($productIds): void {
                        $sub->select('id')->from('product_variation_attributes')->whereIn('product_id', $productIds);
                    });
                })
                ->delete();
        }

        if (Schema::hasTable('product_variation_attributes')) {
            DB::table('product_variation_attributes')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }

        if (Schema::hasTable('products_translations')) {
            DB::table('products_translations')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }

        if (Schema::hasTable('product_attributes')) {
            DB::table('product_attributes')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }

        if (Schema::hasTable('product_images')) {
            DB::table('product_images')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }

        if (Schema::hasTable('media')) {
            DB::table('media')->whereIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES)->delete();
        }

        if (Schema::hasTable('product_fees')) {
            DB::table('product_fees')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }

        if (Schema::hasTable('product_stocks')) {
            DB::table('product_stocks')
                ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
                ->delete();
        }
    }

    private function deleteCurrentStocks(array $productIds, array $variationIds): void
    {
        if (! Schema::hasTable('warehouse_stocks')) {
            return;
        }

        DB::table('warehouse_stocks')
            ->when(true, function ($q) use ($productIds, $variationIds): void {
                $q->where(function ($inner) use ($productIds, $variationIds): void {
                    if ($productIds !== []) {
                        $inner->orWhereIn('product_id', $productIds);
                    }
                    if ($variationIds !== []) {
                        $inner->orWhereIn('product_variation_id', $variationIds);
                    }
                    if ($productIds === [] && $variationIds === []) {
                        // Empty catalog: any warehouse row still pointing at a
                        // (missing) product target is orphaned product state.
                        $inner->whereNotNull('product_id')->orWhereNotNull('product_variation_id');
                    }
                });
            })
            ->delete();
    }

    private function deleteCatalogSkus(): void
    {
        if (! Schema::hasTable('catalog_skus')) {
            return;
        }

        // No FK guards this registry (plain owner_type/owner_id), and every
        // product/variation owner is being removed, so the whole product and
        // variant namespace goes. Nothing else writes these owner types
        // (HasGlobalSku writes exactly 'product' / 'variant').
        DB::table('catalog_skus')->whereIn('owner_type', ['product', 'variant'])->delete();
    }

    /**
     * Physical product files are removed ONLY after the DB transaction has
     * committed. A file failure is returned for reporting; it never rolls
     * back or corrupts the committed database reset.
     *
     * Paths come from database rows, so every path is validated before any
     * filesystem call: only plain relative paths inside the disk root are
     * ever deleted. Anything else (absolute paths, `..` segments, stream
     * wrappers, null bytes) is skipped and reported as a failure so the
     * verification refuses to declare success on ambiguous input.
     *
     * @param  array{product_images:list<string>, media:list<array{disk:string,path:string}>}  $files
     * @return list<string> failed paths
     */
    private function deleteProductFiles(array $files): array
    {
        $failures = [];

        foreach ($files['product_images'] as $path) {
            if (! self::isSafeRelativePath($path)) {
                $failures[] = 'skipped-unsafe:public:'.$path;

                continue;
            }
            try {
                if (Storage::disk('public')->exists($path) && ! Storage::disk('public')->delete($path)) {
                    $failures[] = 'public:'.$path;
                }
            } catch (\Throwable $e) {
                $failures[] = 'public:'.$path;
            }
        }

        foreach ($files['media'] as $file) {
            if (! self::isSafeRelativePath($file['path'])) {
                $failures[] = 'skipped-unsafe:'.($file['disk'] !== '' ? $file['disk'] : 'public').':'.$file['path'];

                continue;
            }
            try {
                $disk = $file['disk'] !== '' ? $file['disk'] : 'public';
                if (Storage::disk($disk)->exists($file['path']) && ! Storage::disk($disk)->delete($file['path'])) {
                    $failures[] = $disk.':'.$file['path'];
                }
            } catch (\Throwable $e) {
                $failures[] = ($file['disk'] !== '' ? $file['disk'] : 'public').':'.$file['path'];
            }
        }

        // Media-table rows for product mediables were already removed inside
        // the transaction; only the bytes are handled here (strictly no DB
        // writes after commit).

        return $failures;
    }

    /**
     * A storage path is deletable only when it is a plain relative path
     * that cannot escape the disk root.
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
     * Preserved slices of shared tables: rows that must be identical before
     * and after the reset (the product-owned slices are verified as zero by
     * the DELETE-scope checks instead).
     *
     * @return array<string,int>
     */
    private function sharedGuards(): array
    {
        $guards = [];

        if (Schema::hasTable('warehouse_stocks')) {
            $guards['warehouse_stocks (non-product rows)'] = (int) DB::table('warehouse_stocks')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        if (Schema::hasTable('media')) {
            $guards['media (non-product rows)'] = (int) DB::table('media')
                ->where(fn ($q) => $q->whereNull('mediable_type')->orWhereNotIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES))
                ->count();
        }

        if (Schema::hasTable('vouchers')) {
            $guards['vouchers (whole-catalog rows)'] = (int) DB::table('vouchers')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        if (Schema::hasTable('product_discounts')) {
            $guards['product_discounts (untargeted rows)'] = (int) DB::table('product_discounts')
                ->whereNull('product_id')->whereNull('product_variation_id')->count();
        }

        return $guards;
    }

    /**
     * @param  array<string,int>  $preservedBefore
     * @param  array<string,int>  $sharedBefore
     * @param  list<string>  $fileFailures
     * @return array{success:bool, delete_after:array<string,int>, preserved_before:array<string,int>, preserved_after:array<string,int>, changed:array<string,int>, shared_changed:array<string,int>, not_empty:array<string,int>, file_failures:list<string>, blockers:array<string,int>}
     */
    private function verifyReset(array $preservedBefore, array $sharedBefore, array $fileFailures): array
    {
        $productIds = $this->existingProductIds();
        $variationIds = $this->existingVariationIds();

        $deleteAfter = [];
        foreach (self::DELETE_TABLES_IN_ORDER as $key) {
            $deleteAfter[$key] = $this->deleteScopeCount($key, $productIds, $variationIds);
        }

        $preservedTables = $this->existingTables(self::PRESERVED_TABLES);
        $preservedAfter = $this->counts($preservedTables);
        $changed = [];
        foreach ($preservedBefore as $table => $count) {
            if (($preservedAfter[$table] ?? null) !== $count) {
                $changed[$table] = $preservedAfter[$table] ?? -1;
            }
        }

        $notEmpty = array_filter($deleteAfter, fn (int $count) => $count !== 0);

        $sharedAfter = $this->sharedGuards();
        $sharedChanged = [];
        foreach ($sharedBefore as $key => $count) {
            if (($sharedAfter[$key] ?? null) !== $count) {
                $sharedChanged[$key] = $sharedAfter[$key] ?? -1;
            }
        }

        $blockers = [];
        foreach (self::BLOCKING_REFERENCES as $table => [$productColumn, $variationColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $count = $this->referencingCount($table, $productColumn, $variationColumn, $productIds, $variationIds);
            if ($count > 0) {
                $blockers[$table] = $count;
            }
        }

        $success = $notEmpty === []
            && $changed === []
            && $sharedChanged === []
            && $blockers === []
            && $fileFailures === []
            && Product::withTrashed()->count() === 0
            && ProductVariation::withTrashed()->count() === 0;

        return [
            'success' => $success,
            'delete_after' => $deleteAfter,
            'preserved_before' => $preservedBefore,
            'preserved_after' => $preservedAfter,
            'changed' => $changed,
            'shared_changed' => $sharedChanged,
            'not_empty' => $notEmpty,
            'file_failures' => $fileFailures,
            'blockers' => $blockers,
        ];
    }

    /** @param array<string,mixed> $verification */
    private function printVerification(array $verification): void
    {
        $this->newLine();
        $this->info('PRODUCT RESET VERIFICATION');
        $this->line('Products (incl. trashed): '.Product::withTrashed()->count());
        $this->line('Variations (incl. trashed): '.ProductVariation::withTrashed()->count());

        $rows = [];
        foreach (self::DELETE_TABLES_IN_ORDER as $key) {
            $rows[] = [$this->displayTable($key), $verification['delete_after'][$key]];
        }
        $this->table(['Product-domain table', 'Rows after'], $rows);

        if ($verification['changed'] !== [] || $verification['shared_changed'] !== []) {
            foreach ($verification['changed'] as $table => $after) {
                $before = $verification['preserved_before'][$table] ?? '?';
                $this->line("PRESERVED CHANGED: {$table} before={$before} after={$after}");
            }
            foreach ($verification['shared_changed'] as $key => $after) {
                $this->line("PRESERVED CHANGED: {$key} after={$after}");
            }
        } else {
            $this->line('Preserved-domain counts unchanged.');
        }

        $this->line('File deletion failures: '.count($verification['file_failures']));
        foreach ($verification['file_failures'] as $failure) {
            $this->line('File failure: '.$failure);
        }
    }

    /** @throws \RuntimeException when a preserved reference blocks deletion */
    private function assertNoBlockers(array $productIds, array $variationIds): void
    {
        foreach (self::BLOCKING_REFERENCES as $table => [$productColumn, $variationColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if ($this->referencingCount($table, $productColumn, $variationColumn, $productIds, $variationIds) > 0) {
                throw new RuntimeException("Blocked by preserved data in {$table}.");
            }
        }

        if ($this->voucherAttributionBlockers($productIds, $variationIds) !== []) {
            throw new RuntimeException('Blocked by preserved order voucher attribution.');
        }
    }

    /**
     * Orders that reference a product-targeted voucher must keep their
     * attribution history, so their existence blocks voucher deletion.
     *
     * @return array<string,int>
     */
    private function voucherAttributionBlockers(array $productIds, array $variationIds): array
    {
        if (! Schema::hasTable('vouchers') || ! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'voucher_id')) {
            return [];
        }

        $voucherIds = DB::table('vouchers')
            ->where(function ($q) use ($productIds, $variationIds): void {
                if ($productIds !== []) {
                    $q->orWhereIn('product_id', $productIds);
                }
                if ($variationIds !== []) {
                    $q->orWhereIn('product_variation_id', $variationIds);
                }
                if ($productIds === [] && $variationIds === []) {
                    $q->whereNotNull('product_id')->orWhereNotNull('product_variation_id');
                }
            })
            ->pluck('id');

        if ($voucherIds->isEmpty()) {
            return [];
        }

        $count = DB::table('orders')->whereIn('voucher_id', $voucherIds)->count();

        return $count > 0 ? ['orders.voucher_id' => $count] : [];
    }

    private function referencingCount(
        string $table,
        ?string $productColumn,
        ?string $variationColumn,
        array $productIds,
        array $variationIds
    ): int {
        // With an empty catalog there is nothing left to reference: any
        // remaining row in these tables cannot reference a product.
        if ($productIds === [] && $variationIds === []) {
            return 0;
        }

        return DB::table($table)
            ->where(function ($q) use ($table, $productColumn, $variationColumn, $productIds, $variationIds): void {
                if ($productColumn !== null && Schema::hasColumn($table, $productColumn) && $productIds !== []) {
                    $q->orWhereIn($productColumn, $productIds);
                }
                if ($variationColumn !== null && Schema::hasColumn($table, $variationColumn) && $variationIds !== []) {
                    $q->orWhereIn($variationColumn, $variationIds);
                }
            })
            ->count();
    }

    private function deleteScopeCount(string $key, array $productIds, array $variationIds): int
    {
        if (! $this->tableExistsForKey($key)) {
            return 0;
        }

        if ($productIds === [] && $variationIds === []) {
            // Empty catalog: every remaining product-domain row is in scope.
            return match ($key) {
                'products' => Product::withTrashed()->count(),
                'product_variations' => ProductVariation::withTrashed()->count(),
                'product_discounts_product_targeted' => Schema::hasTable('product_discounts')
                    ? (int) DB::table('product_discounts')->whereNotNull('product_id')->orWhereNotNull('product_variation_id')->count()
                    : 0,
                'vouchers_product_targeted' => Schema::hasTable('vouchers')
                    ? (int) DB::table('vouchers')->whereNotNull('product_id')->orWhereNotNull('product_variation_id')->count()
                    : 0,
                'media_product_owned' => Schema::hasTable('media')
                    ? (int) DB::table('media')->whereIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES)->count()
                    : 0,
                'catalog_skus_product_owned' => Schema::hasTable('catalog_skus')
                    ? (int) DB::table('catalog_skus')->whereIn('owner_type', ['product', 'variant'])->count()
                    : 0,
                'warehouse_stocks_product_owned' => Schema::hasTable('warehouse_stocks')
                    ? (int) DB::table('warehouse_stocks')->whereNotNull('product_id')->orWhereNotNull('product_variation_id')->count()
                    : 0,
                default => (int) DB::table($this->physicalTable($key))->count(),
            };
        }

        return match ($key) {
            'products' => count($productIds),
            'product_variations' => count($variationIds),
            'product_variation_compositions' => $this->countWhereIn('product_variation_compositions', 'product_variation_id', $variationIds),
            'product_variation_attribute_options' => $this->countOptionsForProducts($productIds),
            'product_variation_attribute_options_translations' => $this->countOptionTranslationsForProducts($productIds),
            'product_variation_attributes' => $this->countWhereIn('product_variation_attributes', 'product_id', $productIds),
            'product_variation_attributes_translations' => $this->countAttributeTranslationsForProducts($productIds),
            'products_translations' => $this->countWhereIn('products_translations', 'product_id', $productIds),
            'product_attributes' => $this->countWhereIn('product_attributes', 'product_id', $productIds),
            'product_images' => $this->countImagesFor($productIds, $variationIds),
            'media_product_owned' => (int) DB::table('media')->whereIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES)->count(),
            'product_fees' => $this->countWhereIn('product_fees', 'product_id', $productIds),
            'product_variation_fees' => $this->countWhereIn('product_variation_fees', 'product_variation_id', $variationIds),
            'product_discounts_product_targeted' => $this->countTargeted('product_discounts', $productIds, $variationIds),
            'vouchers_product_targeted' => $this->countTargeted('vouchers', $productIds, $variationIds),
            'product_stocks' => $this->countWhereIn('product_stocks', 'product_id', $productIds),
            'product_variation_stocks' => $this->countWhereIn('product_variation_stocks', 'product_variation_id', $variationIds),
            'warehouse_stocks_product_owned' => $this->countWarehouseStocksFor($productIds, $variationIds),
            'catalog_skus_product_owned' => (int) DB::table('catalog_skus')->whereIn('owner_type', ['product', 'variant'])->count(),
        };
    }

    private function countWhereIn(string $table, string $column, array $ids): int
    {
        if (! Schema::hasTable($table) || $ids === []) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $ids)->count();
    }

    private function countImagesFor(array $productIds, array $variationIds): int
    {
        if (! Schema::hasTable('product_images')) {
            return 0;
        }

        return (int) DB::table('product_images')
            ->where(function ($q) use ($productIds, $variationIds): void {
                if ($productIds !== []) {
                    $q->orWhereIn('product_id', $productIds);
                }
                if ($variationIds !== []) {
                    $q->orWhereIn('product_variation_id', $variationIds);
                }
            })
            ->count();
    }

    private function countOptionsForProducts(array $productIds): int
    {
        if (! Schema::hasTable('product_variation_attribute_options') || $productIds === []) {
            return 0;
        }

        return (int) DB::table('product_variation_attribute_options')
            ->whereIn('product_variation_attribute_id', function ($sub) use ($productIds): void {
                $sub->select('id')->from('product_variation_attributes')->whereIn('product_id', $productIds);
            })
            ->count();
    }

    private function countOptionTranslationsForProducts(array $productIds): int
    {
        if (! Schema::hasTable('product_variation_attribute_options_translations') || $productIds === []) {
            return 0;
        }

        return (int) DB::table('product_variation_attribute_options_translations')
            ->whereIn('product_variation_attribute_option_id', function ($sub) use ($productIds): void {
                $sub->select('product_variation_attribute_options.id')
                    ->from('product_variation_attribute_options')
                    ->join(
                        'product_variation_attributes',
                        'product_variation_attributes.id',
                        '=',
                        'product_variation_attribute_options.product_variation_attribute_id'
                    )
                    ->whereIn('product_variation_attributes.product_id', $productIds);
            })
            ->count();
    }

    private function countAttributeTranslationsForProducts(array $productIds): int
    {
        if (! Schema::hasTable('product_variation_attributes_translations') || $productIds === []) {
            return 0;
        }

        return (int) DB::table('product_variation_attributes_translations')
            ->whereIn('product_variation_attribute_id', function ($sub) use ($productIds): void {
                $sub->select('id')->from('product_variation_attributes')->whereIn('product_id', $productIds);
            })
            ->count();
    }

    private function countTargeted(string $table, array $productIds, array $variationIds): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)
            ->where(function ($q) use ($productIds, $variationIds): void {
                if ($productIds !== []) {
                    $q->orWhereIn('product_id', $productIds);
                }
                if ($variationIds !== []) {
                    $q->orWhereIn('product_variation_id', $variationIds);
                }
            })
            ->count();
    }

    private function countWarehouseStocksFor(array $productIds, array $variationIds): int
    {
        if (! Schema::hasTable('warehouse_stocks')) {
            return 0;
        }

        return (int) DB::table('warehouse_stocks')
            ->where(function ($q) use ($productIds, $variationIds): void {
                if ($productIds !== []) {
                    $q->orWhereIn('product_id', $productIds);
                }
                if ($variationIds !== []) {
                    $q->orWhereIn('product_variation_id', $variationIds);
                }
            })
            ->count();
    }

    /** @return list<int> */
    private function existingProductIds(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        return Product::withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function existingVariationIds(): array
    {
        if (! Schema::hasTable('product_variations')) {
            return [];
        }

        return ProductVariation::withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function productImageFileCount(): int
    {
        if (! Schema::hasTable('product_images')) {
            return 0;
        }

        return (int) DB::table('product_images')->whereNotNull('path')->count();
    }

    private function productMediaFileCount(): int
    {
        if (! Schema::hasTable('media')) {
            return 0;
        }

        return (int) DB::table('media')->whereIn('mediable_type', self::PRODUCT_MEDIABLE_TYPES)->count();
    }

    private function tableExistsForKey(string $key): bool
    {
        return match ($key) {
            'products', 'product_variations' => Schema::hasTable($key),
            'media_product_owned' => Schema::hasTable('media'),
            'product_discounts_product_targeted' => Schema::hasTable('product_discounts'),
            'vouchers_product_targeted' => Schema::hasTable('vouchers'),
            'warehouse_stocks_product_owned' => Schema::hasTable('warehouse_stocks'),
            'catalog_skus_product_owned' => Schema::hasTable('catalog_skus'),
            default => Schema::hasTable($key),
        };
    }

    private function physicalTable(string $key): string
    {
        return $key;
    }

    private function displayTable(string $key): string
    {
        return match ($key) {
            'media_product_owned' => 'media (product-owned)',
            'product_discounts_product_targeted' => 'product_discounts (product-targeted)',
            'vouchers_product_targeted' => 'vouchers (product-targeted)',
            'warehouse_stocks_product_owned' => 'warehouse_stocks (product-owned)',
            'catalog_skus_product_owned' => 'catalog_skus (product-owned)',
            default => $key,
        };
    }

    /** @param list<string> $tables @return array<string,int> */
    private function counts(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => (int) DB::table($table)->count()])
            ->all();
    }

    /** @param list<string> $tables @return list<string> */
    private function existingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table) => Schema::hasTable($table)));
    }
}
