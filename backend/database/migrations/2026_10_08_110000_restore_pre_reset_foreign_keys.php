<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEV SCHEMA DRIFT REMEDIATION — PHASE A (P1). Restores 91 of the 97 foreign-key
 * constraints that the `migrations` table claims are applied but that the DEV
 * schema does not actually carry. Inventory and evidence:
 * docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md.
 *
 * WHAT IS IN THIS FILE
 *   The 91 constraints with **zero orphan rows**, excluding `villages.district_id`
 *   (its own file — the only material table rebuild) and the 5 constraints blocked
 *   by orphans (their own file — they may only run after the orphan cleanup).
 *   Every constraint is re-declared verbatim from the migration that originally
 *   created it: name `<table>_<column>_foreign`, `ON DELETE` as the original
 *   `restrictOnDelete()` / `nullOnDelete()` / `cascadeOnDelete()`, `ON UPDATE
 *   RESTRICT` (Laravel emits no `onUpdate` anywhere in this repository, so every
 *   constraint inherits the MySQL default).
 *
 * WHY THIS MUST RUN BEFORE `products:reset`
 *   The reset is the only operation that clears the
 *   `warehouse_migration_markers` orphans. Running it against an FK-unprotected
 *   DEV schema means a child-before-parent mistake is not caught by the database.
 *   Once this file has run, the DEV database enforces the same constraint set as
 *   production, so the destructive phase runs under real protection.
 *
 * SAFETY PROPERTIES
 *   - Idempotent by construction: each constraint is skipped when
 *     `information_schema.REFERENTIAL_CONSTRAINTS` already reports it, so this is
 *     a **no-op** on `primeclassy_testing` and on any correctly migrated database.
 *     Reruns after a partial failure therefore only do the remaining work.
 *   - Fails closed BEFORE any DDL: every constraint that is actually missing is
 *     preflighted (both tables/columns exist, column types match, a NOT NULL
 *     child does not reference a nullable column, an index already starts on the
 *     child column, and no row violates the constraint). If any check fails the
 *     whole file aborts with a precise report and **not a single ALTER runs**.
 *   - Foreign-key validation stays ON. `ALGORITHM=INPLACE` without a rebuild is
 *     only available when `foreign_key_checks = 0`, and in that mode existing rows
 *     are NOT validated — the database would then hold historical rows that
 *     violate the constraint it claims to enforce. The rebuild cost is accepted
 *     instead; orphan errno 1452 is resolved by fixing the orphan, never by
 *     disabling checks.
 *   - One constraint per statement, so a failure names the exact single offender.
 *   - No `disableForeignKeyConstraints()`, no `FOREIGN_KEY_CHECKS=0`, no
 *     `migrate:fresh`, no truncate, no raw DELETE, no index rebuild, no data edit.
 *
 * LOCKING (see docs/OPERATIONS.md §8.3)
 *   Each `ADD CONSTRAINT` is a metadata change plus a data re-validation, so it
 *   takes a brief exclusive metadata lock per table. Every table in this file holds
 *   at most 32 rows and at most 224 KB, so the total exclusive time is sub-second
 *   and reads are unaffected. `villages` (83,202 rows) is deliberately NOT here.
 *
 * ON A NON-MySQL/MariaDB DRIVER
 *   The migration does nothing. Absence of `information_schema` means absence of
 *   *proof*, and a constraint must never be assumed absent.
 *
 * `down()` IS DELIBERATELY EMPTY
 *   These constraints are declared by the original create-table migrations. In a
 *   correctly migrated database this file added nothing, so rolling it back must
 *   not remove 91 protections while those create migrations stay recorded as
 *   applied. `migrate:rollback` therefore only un-records this file; `up()` stays
 *   idempotent, so re-running it re-checks (and finds nothing to do) and the end
 *   state is correct either way. The inverse operation for the drifted DEV schema
 *   is the explicit `ALTER TABLE ... DROP FOREIGN KEY` list in
 *   docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md §5, which is metadata-only and
 *   instant — it is deliberately not automated, because dropping protections is
 *   never something a rollback should do silently.
 *
 * The driver checks below are intentionally duplicated in each of the three
 * restoration migrations: this repository keeps every migration self-contained
 * (no migration references an `App\` class), so a migration must keep working even
 * if application code is refactored later.
 */
return new class extends Migration
{
    /**
     * [child table, child column, referenced table, referenced column, ON DELETE]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const CONSTRAINTS = [
        ['shipping_configurations', 'shipping_provider_id', 'shipping_providers', 'id', 'set null'],
        ['stock_handovers', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_handovers', 'handed_over_by', 'users', 'id', 'restrict'],
        ['stock_handovers', 'received_by', 'users', 'id', 'set null'],
        ['stock_handovers', 'stock_transfer_id', 'stock_transfers', 'id', 'restrict'],
        ['stock_movements', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_movements', 'created_by', 'users', 'id', 'set null'],
        ['stock_movements', 'opname_id', 'stock_opnames', 'id', 'restrict'],
        ['stock_movements', 'product_id', 'products', 'id', 'restrict'],
        ['stock_movements', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['stock_movements', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['stock_movements', 'warehouse_stock_request_id', 'warehouse_stock_requests', 'id', 'set null'],
        ['stock_opname_items', 'product_id', 'products', 'id', 'restrict'],
        ['stock_opname_items', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['stock_opname_items', 'stock_opname_id', 'stock_opnames', 'id', 'cascade'],
        ['stock_opname_items', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['stock_opnames', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_opnames', 'approved_by', 'users', 'id', 'set null'],
        ['stock_opnames', 'created_by', 'users', 'id', 'restrict'],
        ['stock_opnames', 'rejected_by', 'users', 'id', 'set null'],
        ['stock_opnames', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['stock_opnames', 'submitted_by', 'users', 'id', 'set null'],
        ['stock_request_fulfillments', 'fulfilled_by', 'users', 'id', 'restrict'],
        ['stock_request_fulfillments', 'stock_request_id', 'stock_requests', 'id', 'restrict'],
        ['stock_request_items', 'order_item_id', 'order_items', 'id', 'restrict'],
        ['stock_request_items', 'product_id', 'products', 'id', 'restrict'],
        ['stock_request_items', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['stock_request_items', 'stock_request_id', 'stock_requests', 'id', 'cascade'],
        ['stock_request_proposal_items', 'stock_request_item_id', 'stock_request_items', 'id', 'restrict'],
        ['stock_request_proposal_items', 'stock_request_proposal_id', 'stock_request_proposals', 'id', 'cascade'],
        ['stock_request_proposals', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_request_proposals', 'approved_by', 'users', 'id', 'set null'],
        ['stock_request_proposals', 'rejected_by', 'users', 'id', 'set null'],
        ['stock_request_proposals', 'requested_by', 'users', 'id', 'restrict'],
        ['stock_request_proposals', 'stock_request_id', 'stock_requests', 'id', 'restrict'],
        ['stock_requests', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_requests', 'created_by', 'users', 'id', 'set null'],
        ['stock_requests', 'order_id', 'orders', 'id', 'restrict'],
        ['stock_transfer_items', 'product_id', 'products', 'id', 'restrict'],
        ['stock_transfer_items', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['stock_transfer_items', 'stock_transfer_id', 'stock_transfers', 'id', 'cascade'],
        ['stock_transfers', 'agent_id', 'users', 'id', 'restrict'],
        ['stock_transfers', 'completed_by', 'users', 'id', 'set null'],
        ['stock_transfers', 'created_by', 'users', 'id', 'restrict'],
        ['stock_transfers', 'destination_sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['stock_transfers', 'rejected_by', 'users', 'id', 'set null'],
        ['stock_transfers', 'source_sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['sub_stock_request_items', 'product_id', 'products', 'id', 'restrict'],
        ['sub_stock_request_items', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['sub_stock_request_items', 'sub_stock_request_id', 'sub_stock_requests', 'id', 'cascade'],
        ['sub_stock_requests', 'agent_id', 'users', 'id', 'restrict'],
        ['sub_stock_requests', 'approved_by', 'users', 'id', 'set null'],
        ['sub_stock_requests', 'executed_by', 'users', 'id', 'set null'],
        ['sub_stock_requests', 'received_by', 'users', 'id', 'set null'],
        ['sub_stock_requests', 'rejected_by', 'users', 'id', 'set null'],
        ['sub_stock_requests', 'requested_by', 'users', 'id', 'restrict'],
        ['sub_stock_requests', 'stock_transfer_id', 'stock_transfers', 'id', 'restrict'],
        ['sub_stock_requests', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['sub_stock_reservations', 'agent_id', 'users', 'id', 'restrict'],
        ['sub_stock_reservations', 'consumed_by', 'users', 'id', 'set null'],
        ['sub_stock_reservations', 'order_item_id', 'order_items', 'id', 'restrict'],
        ['sub_stock_reservations', 'product_id', 'products', 'id', 'restrict'],
        ['sub_stock_reservations', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['sub_stock_reservations', 'released_by', 'users', 'id', 'set null'],
        ['sub_stock_reservations', 'reserved_by', 'users', 'id', 'set null'],
        ['sub_stock_reservations', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['users', 'agent_id', 'users', 'id', 'set null'],
        ['users', 'avatar_media_id', 'media', 'id', 'set null'],
        ['users', 'korsal_id', 'users', 'id', 'set null'],
        ['users', 'parent_id', 'users', 'id', 'set null'],
        ['users', 'role_id', 'roles', 'id', 'restrict'],
        ['users', 'sales_id', 'users', 'id', 'set null'],
        ['warehouse_migration_markers', 'agent_id', 'users', 'id', 'restrict'],
        ['warehouse_migration_markers', 'run_id', 'warehouse_migration_runs', 'id', 'cascade'],
        ['warehouse_migration_runs', 'started_by', 'users', 'id', 'set null'],
        ['warehouse_settings', 'agent_id', 'users', 'id', 'restrict'],
        ['warehouse_stock_requests', 'agent_id', 'users', 'id', 'restrict'],
        ['warehouse_stock_requests', 'approved_by', 'users', 'id', 'set null'],
        ['warehouse_stock_requests', 'product_id', 'products', 'id', 'restrict'],
        ['warehouse_stock_requests', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['warehouse_stock_requests', 'rejected_by', 'users', 'id', 'set null'],
        ['warehouse_stock_requests', 'requested_by', 'users', 'id', 'restrict'],
        ['warehouse_stock_requests', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['warehouse_stocks', 'agent_id', 'users', 'id', 'restrict'],
        ['warehouse_stocks', 'product_id', 'products', 'id', 'restrict'],
        ['warehouse_stocks', 'product_variation_id', 'product_variations', 'id', 'restrict'],
        ['warehouse_stocks', 'sub_location_id', 'warehouse_sub_locations', 'id', 'restrict'],
        ['warehouse_sub_locations', 'agent_id', 'users', 'id', 'restrict'],
        ['warehouse_sub_locations', 'created_by', 'users', 'id', 'restrict'],
        ['warehouse_sub_locations', 'owner_user_id', 'users', 'id', 'restrict'],
        ['warehouse_sub_locations', 'previous_owner_user_id', 'users', 'id', 'restrict'],
    ];

    public function up(): void
    {
        $this->restore(self::CONSTRAINTS);
    }

    /** See the class docblock: intentionally empty so a rollback can never remove a protection. */
    public function down(): void {}

    /** @param array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}> $constraints */
    private function restore(array $constraints): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $missing = [];
        $conflicts = [];
        foreach ($constraints as [$table, $column, $parent, $parentColumn, $deleteRule]) {
            if ($this->foreignKeyExists($table, $this->constraintName($table, $column))) {
                if (! $this->foreignKeyMatches($table, $column, $parent, $parentColumn, $deleteRule)) {
                    $conflicts[] = "{$table}.{$column}: existing constraint `{$this->constraintName($table, $column)}` has a different definition; expected {$parent}.{$parentColumn} ON DELETE ".strtoupper($deleteRule).' ON UPDATE RESTRICT';
                }

                continue;
            }
            $missing[] = [$table, $column, $parent, $parentColumn, $deleteRule];
        }

        if ($conflicts !== []) {
            throw new RuntimeException("Foreign-key restoration refused; existing named constraints conflict and no constraint was changed:\n  - ".implode("\n  - ", $conflicts));
        }

        if ($missing === []) {
            return;
        }

        // Nothing above this line has changed the schema. Everything below runs
        // only after the whole set has been proven addable.
        $this->assertRestorable($missing);

        foreach ($missing as [$table, $column, $parent, $parentColumn, $deleteRule]) {
            DB::statement(sprintf(
                'alter table `%s` add constraint `%s` foreign key (`%s`) references `%s` (`%s`) on delete %s on update restrict',
                $table,
                $this->constraintName($table, $column),
                $column,
                $parent,
                $parentColumn,
                $deleteRule
            ));
        }
    }

    /**
     * Fails closed with the complete list of blockers and zero mutations.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>  $constraints
     */
    private function assertRestorable(array $constraints): void
    {
        $problems = [];

        foreach ($constraints as [$table, $column, $parent, $parentColumn]) {
            $label = "{$table}.{$column} -> {$parent}.{$parentColumn}";

            if (! Schema::hasTable($table)) {
                $problems[] = "{$label}: child table `{$table}` does not exist";

                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "{$label}: child column `{$table}`.`{$column}` does not exist";

                continue;
            }

            if (! Schema::hasTable($parent)) {
                $problems[] = "{$label}: referenced table `{$parent}` does not exist";

                continue;
            }

            if (! Schema::hasColumn($parent, $parentColumn)) {
                $problems[] = "{$label}: referenced column `{$parent}`.`{$parentColumn}` does not exist";

                continue;
            }

            [$childType, $childNullable] = $this->columnDefinition($table, $column);
            [$parentType, $parentNullable] = $this->columnDefinition($parent, $parentColumn);

            if ($childType !== $parentType) {
                $problems[] = "{$label}: column type `{$childType}` does not match `{$parentType}`";
            }

            if (! $childNullable && $parentNullable) {
                $problems[] = "{$label}: a NOT NULL column cannot reference a nullable column";
            }

            if (! $this->hasLeadingIndex($table, $column)) {
                $problems[] = "{$label}: no existing index starts on `{$table}`.`{$column}` — the constraint would have to build one";
            }

            if ($this->hasOrphan($table, $column, $parent, $parentColumn)) {
                $problems[] = "{$label}: existing rows reference a `{$parent}` row that does not exist — resolve the orphan, never disable foreign-key checks";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Foreign-key restoration refused; no constraint was changed:\n  - ".implode("\n  - ", $problems)
            );
        }
    }

    private function constraintName(string $table, string $column): string
    {
        return "{$table}_{$column}_foreign";
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.referential_constraints
             where constraint_schema = ? and table_name = ? and constraint_name = ?',
            [DB::getDatabaseName(), $table, $constraint]
        ) !== null;
    }

    private function foreignKeyMatches(string $table, string $column, string $parent, string $parentColumn, string $deleteRule): bool
    {
        $rows = DB::select(
            'select k.column_name, k.referenced_table_name, k.referenced_column_name,
                    r.delete_rule, r.update_rule
               from information_schema.key_column_usage k
               join information_schema.referential_constraints r
                 on r.constraint_schema = k.constraint_schema and r.table_name = k.table_name
                and r.constraint_name = k.constraint_name
              where k.constraint_schema = ? and k.table_name = ? and k.constraint_name = ?
                and k.referenced_table_name is not null',
            [DB::getDatabaseName(), $table, $this->constraintName($table, $column)]
        );

        return count($rows) === 1
            && (string) $rows[0]->column_name === $column
            && (string) $rows[0]->referenced_table_name === $parent
            && (string) $rows[0]->referenced_column_name === $parentColumn
            && strtoupper((string) $rows[0]->delete_rule) === strtoupper($deleteRule)
            && strtoupper((string) $rows[0]->update_rule) === 'RESTRICT';
    }

    /** @return array{0: ?string, 1: bool} [lowercased column type, nullable] */
    private function columnDefinition(string $table, string $column): array
    {
        $row = DB::selectOne(
            'select column_type, is_nullable from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        if ($row === null) {
            return [null, false];
        }

        return [strtolower((string) $row->column_type), strtoupper((string) $row->is_nullable) === 'YES'];
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
             where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1 limit 1',
            [DB::getDatabaseName(), $table, $column]
        ) !== null;
    }

    private function hasOrphan(string $table, string $column, string $parent, string $parentColumn): bool
    {
        $row = DB::selectOne(sprintf(
            'select exists(select 1 from `%s` where `%s` is not null and `%s` not in (select `%s` from `%s`) limit 1) as orphan_exists',
            $table,
            $column,
            $column,
            $parentColumn,
            $parent
        ));

        return $row !== null && (int) $row->orphan_exists === 1;
    }
};
