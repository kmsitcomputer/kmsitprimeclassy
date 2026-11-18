<?php

use App\Services\Product\CategoryHierarchyService;
use App\Support\CategoryDuplicateScanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REM-06 DB hardening — PROPOSED, NOT YET AUTHORISED TO RUN (lives in database/proposed-migrations/, which the
 * migration runner does not scan). Owner Decision C: prepare only.
 *
 * What it adds (additive, no category is deleted, merged, renamed or moved):
 *   name_key       VARCHAR(255) utf8mb4_bin NOT NULL   — the application's normalised key
 *                  (CategoryHierarchyService::nameKey: NFC, control/zero-width removal, whitespace collapse, case-fold),
 *                  materialised by the application (ProductCategory `saving` hook) because neither MariaDB nor MySQL can
 *                  compute NFC natively (verified: utf8mb4_unicode_ci treats NFD ≠ NFC but folds accents).
 *   root_name_key  VARCHAR(255) utf8mb4_bin VIRTUAL    — name_key when parent_id IS NULL, else NULL (see below)
 *   CHECK (name_key <> '')                              — blocks empty keys even in non-strict SQL modes
 *   UNIQUE (parent_id, name_key)                        — siblings under the same non-null parent
 *   UNIQUE (root_name_key)                              — root categories (a plain UNIQUE would ignore NULL parents;
 *                                                         a STORED COALESCE(parent_id,0) column is rejected by the engine
 *                                                         because parent_id is an FK column with ON DELETE SET NULL — error 1901)
 *
 * Fail-closed preconditions (checked BEFORE any DDL): supported engine/version, DYNAMIC row format, no application-level
 * duplicate and no empty normalised name in existing data. It serialises with category creation through the same advisory
 * lock the application uses, so no category can be created between the check and the index.
 *
 * Operational order (Owner/DevOps): 1) deploy the application code that contains the ProductCategory `saving` hook
 * (works with and without the column), 2) run `php artisan categories:unique-preflight` (must exit 0), 3) back up,
 * 4) maintenance window, 5) run this migration. Running it BEFORE step 1 would make the old code's inserts fail (NOT NULL).
 *
 * Rollback (`down`) drops the two unique indexes, the CHECK and both columns; it loses nothing but derived data.
 * If `up` is interrupted after DDL started (MySQL DDL is not transactional) `down` is tolerant of partial states.
 */
return new class extends Migration
{
    private const LOCK = 'primeclassy:product_categories:create';

    private const T = 'product_categories';

    public function up(): void
    {
        $this->assertSupportedEngine();
        if (! Schema::hasTable(self::T)) {
            throw new RuntimeException('product_categories table is missing.');
        }

        $got = (int) (DB::selectOne('SELECT GET_LOCK(?, 30) AS got', [self::LOCK])->got ?? 0);
        if ($got !== 1) {
            throw new RuntimeException('Could not acquire the category creation lock within 30s; aborting before any change.');
        }

        try {
            if (Schema::hasColumn(self::T, 'name_key') && $this->indexExists('uq_product_categories_parent_name_key') && $this->indexExists('uq_product_categories_root_name_key')) {
                return; // already hardened: idempotent
            }

            // ---- preconditions: nothing below runs if the data cannot satisfy the constraint ----
            $report = CategoryDuplicateScanner::scan();
            if ($report['groups'] !== [] || $report['empty_keys'] !== []) {
                $ids = collect($report['groups'])->flatMap(fn ($g) => collect($g['members'])->pluck('category_id'))->merge($report['empty_keys'])->unique()->sort()->values()->implode(',');
                throw new RuntimeException(sprintf(
                    'ABORTED before any DDL: %d duplicate group(s) and %d empty-name row(s) under the application normalisation (category ids: %s). Resolve them (Owner decision) and re-run `categories:unique-preflight`.',
                    count($report['groups']), count($report['empty_keys']), $ids,
                ));
            }

            // ---- DDL (additive) ----
            if (! Schema::hasColumn(self::T, 'name_key')) {
                DB::statement('ALTER TABLE `'.self::T.'` ADD COLUMN `name_key` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER `name`');
            }

            // ---- backfill with the application's own normalisation (idempotent) ----
            DB::table(self::T)->orderBy('id')->select(['id', 'name', 'name_key'])->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    $key = CategoryHierarchyService::nameKey((string) $r->name);
                    if ($r->name_key !== $key) {
                        DB::table(self::T)->where('id', $r->id)->update(['name_key' => $key]);
                    }
                }
            });

            DB::statement('ALTER TABLE `'.self::T.'` MODIFY `name_key` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            if (! $this->checkExists('chk_product_categories_name_key_nonempty')) {
                DB::statement('ALTER TABLE `'.self::T."` ADD CONSTRAINT `chk_product_categories_name_key_nonempty` CHECK (`name_key` <> '')");
            }
            if (! Schema::hasColumn(self::T, 'root_name_key')) {
                DB::statement('ALTER TABLE `'.self::T.'` ADD COLUMN `root_name_key` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (IF(`parent_id` IS NULL, `name_key`, NULL)) VIRTUAL');
            }
            if (! $this->indexExists('uq_product_categories_parent_name_key')) {
                DB::statement('ALTER TABLE `'.self::T.'` ADD UNIQUE KEY `uq_product_categories_parent_name_key` (`parent_id`, `name_key`)');
            }
            if (! $this->indexExists('uq_product_categories_root_name_key')) {
                DB::statement('ALTER TABLE `'.self::T.'` ADD UNIQUE KEY `uq_product_categories_root_name_key` (`root_name_key`)');
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
        }
    }

    public function down(): void
    {
        $engine = $this->assertSupportedEngine();
        foreach (['uq_product_categories_root_name_key', 'uq_product_categories_parent_name_key'] as $index) {
            if ($this->indexExists($index)) {
                DB::statement('ALTER TABLE `'.self::T.'` DROP INDEX `'.$index.'`');
            }
        }
        if ($this->checkExists('chk_product_categories_name_key_nonempty')) {
            DB::statement('ALTER TABLE `'.self::T.'` '.($engine === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK').' `chk_product_categories_name_key_nonempty`');
        }
        foreach (['root_name_key', 'name_key'] as $column) {
            if (Schema::hasColumn(self::T, $column)) {
                DB::statement('ALTER TABLE `'.self::T.'` DROP COLUMN `'.$column.'`');
            }
        }
    }

    /** @return 'mariadb'|'mysql' */
    private function assertSupportedEngine(): string
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Unsupported database driver (MySQL/MariaDB required).');
        }
        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        $isMaria = stripos($version, 'mariadb') !== false;
        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m);
        $v = ((int) ($m[1] ?? 0)) * 10000 + ((int) ($m[2] ?? 0)) * 100 + (int) ($m[3] ?? 0);
        // MariaDB: CHECK constraints (10.2.1) + indexed virtual columns (10.2.3) → require 10.3+.  MySQL: CHECK enforced from 8.0.16.
        if (($isMaria && $v < 100300) || (! $isMaria && $v < 80016)) {
            throw new RuntimeException("Unsupported database version {$version} (need MariaDB >= 10.3 or MySQL >= 8.0.16).");
        }
        $format = strtoupper((string) (DB::selectOne('SELECT ROW_FORMAT AS f FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [self::T])->f ?? ''));
        if ($format !== '' && ! in_array($format, ['DYNAMIC', 'COMPRESSED'], true)) {
            throw new RuntimeException("product_categories uses ROW_FORMAT={$format}; a 1020-byte utf8mb4 key needs DYNAMIC (index prefix limit). Aborting.");
        }

        return $isMaria ? 'mariadb' : 'mysql';
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [self::T, $name]) !== null;
    }

    private function checkExists(string $name): bool
    {
        return DB::selectOne("SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK' LIMIT 1", [self::T, $name]) !== null;
    }
};
