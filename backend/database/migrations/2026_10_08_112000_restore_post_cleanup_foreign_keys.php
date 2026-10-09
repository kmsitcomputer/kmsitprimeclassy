<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEV SCHEMA DRIFT REMEDIATION — PHASE C (P4a/P4b/P4c). Restores the LAST 5 of the
 * 97 missing constraints — the ones whose existing DEV rows point at catalogue,
 * identity or configuration rows that no longer exist. Inventory and the per-conflict
 * Human decisions: docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md §3.
 *
 * WHY THIS FILE IS LAST
 *   These are exactly the constraints that `ADD CONSTRAINT` cannot execute on the
 *   audited DEV baseline, because InnoDB re-validates existing rows with
 *   `foreign_key_checks` ON and aborts with errno 1452:
 *
 *     constraint                                     orphan rows  resolved by
 *     shipping_configurations.agent_id               1            P4b — delete the dead rate card (id=1, owner user 2 is gone)
 *     user_closures.ancestor_id                      1            P4a — delete closure edge id=11 (users 2 and 7 are gone)
 *     user_closures.descendant_id                    1            (the same single row)
 *     warehouse_migration_markers.product_id         1            P3  — `products:reset` (product 11 is gone)
 *     warehouse_migration_markers.product_variation_id 3         P3  — `products:reset` (variations 32-34 are gone)
 *
 *   So the required order is Phase A -> Phase B -> `products:reset` -> P4a -> P4b ->
 *   this file. `products:reset` clears the four marker orphans by itself (they are
 *   already inside its locked delete scope), and the two single-row deletions are the
 *   only data deletions in the whole remediation — each needs its own Human
 *   authorization plus a verified backup.
 *
 *   Re-creating the missing parents instead (products 11, variations 32-34, users 2
 *   and 7) is explicitly FORBIDDEN: it would resurrect deliberately deleted catalogue
 *   identities and silently remap historical inventory and referral history
 *   (AGENTS.md §5/§9). This migration therefore never invents a parent row.
 *
 * FAIL-CLOSED PREFLIGHT IS THE POINT OF THIS FILE
 *   On a database where the cleanup has not happened yet — including production, if
 *   production turns out to be drifted too — `up()` must refuse the WHOLE file with a
 *   precise report and zero mutations, not apply four of five and stop. That is why the
 *   orphan check runs before the first ALTER rather than relying on errno 1452.
 *
 *   The migration is recorded as applied only when it completes. A refusal therefore
 *   leaves it pending, so the next `php artisan migrate` retries it after the cleanup —
 *   which is the intended workflow, not a retry loop over a broken state.
 *
 * SAFETY PROPERTIES — identical to Phase A and for the same reasons:
 *   - Idempotent: skipped per constraint when `information_schema.REFERENTIAL_CONSTRAINTS`
 *     already reports it, so this is a no-op on `primeclassy_testing` and on any correctly
 *     migrated database.
 *   - Fails closed before any DDL: both tables/columns exist, types match, no NOT NULL
 *     child references a nullable parent, an index already starts on every child column,
 *     and zero rows violate any constraint in the file.
 *   - One statement per constraint, so a failure names the exact single offender.
 *   - No `foreign_key_checks=0`, no `disableForeignKeyConstraints()`, no `migrate:fresh`,
 *     no truncate, no raw DELETE: this file changes schema only. It never deletes the
 *     orphan rows itself — that is an authorized operational step with its own backup.
 *   - Not run on a non-MySQL/MariaDB driver: absence of `information_schema` is absence
 *     of proof, and a constraint must never be assumed absent.
 *
 * ROLLBACK LIMITATION
 *   `ALTER TABLE ... DROP FOREIGN KEY` is metadata-only and instant, but for
 *   `user_closures` the constraint's ON DELETE CASCADE is what guarantees the closure
 *   stays consistent for future writes. Dropping it to "undo" the restoration would
 *   leave future user deletions able to corrupt the hierarchy unreferenced, so the
 *   inverse is deliberately operator-driven (docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md
 *   §5), never automated by `down()`.
 *
 * The driver checks below are intentionally duplicated from Phase A: this repository
 * keeps every migration self-contained (no migration references an `App\` class), so a
 * migration must keep working even if application code is refactored later.
 */
return new class extends Migration
{
    /**
     * [child table, child column, referenced table, referenced column, ON DELETE]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const CONSTRAINTS = [
        ['shipping_configurations', 'agent_id', 'users', 'id', 'cascade'],
        ['user_closures', 'ancestor_id', 'users', 'id', 'cascade'],
        ['user_closures', 'descendant_id', 'users', 'id', 'cascade'],
        ['warehouse_migration_markers', 'product_id', 'products', 'id', 'restrict'],
        ['warehouse_migration_markers', 'product_variation_id', 'product_variations', 'id', 'restrict'],
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
                $problems[] = "{$label}: existing rows reference a `{$parent}` row that does not exist — resolve the orphan (products:reset or an authorized single-row deletion), never disable foreign-key checks";
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
