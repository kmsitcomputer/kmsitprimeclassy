<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEV SCHEMA DRIFT REMEDIATION — PHASE B (P2). Restores `villages.district_id ->
 * districts.id ON DELETE CASCADE`, the single largest constraint of the set and
 * the only one that is deliberately isolated in its own migration.
 *
 * WHY IT IS NOT IN PHASE A
 *   `villages` holds 83,202 rows / ~8.2 MB. With foreign-key validation ON (it
 *   must stay ON — see the Phase A docblock), adding the constraint re-validates
 *   every row, so InnoDB rebuilds the table under an exclusive lock. That is the
 *   only material write lock in the whole remediation, and it must be schedulable
 *   on its own (DEV: measure it; production: a separate measured window, never an
 *   extrapolation from DEV). Keeping it out of Phase A means Phase A's sub-second
 *   lock profile is not coupled to it, and a failure here cannot leave Phase A
 *   half-applied.
 *
 * SAFETY PROPERTIES — identical to Phase A and for the same reasons:
 *   - Idempotent: skipped when `information_schema.REFERENTIAL_CONSTRAINTS`
 *     already reports `villages_district_id_foreign`, so this is a no-op on
 *     `primeclassy_testing` and on any correctly migrated database. Re-running it
 *     after a lock-timeout abort only does the remaining work.
 *   - Fails closed before any DDL: the existing `villages_district_id_index` is
 *     required, the column types must match, and zero rows may reference a missing
 *     district. On the audited DEV baseline all three hold (83,202 rows, 0 orphans).
 *   - One statement, no `foreign_key_checks=0`, no `migrate:fresh`, no data edit,
 *     no index rebuild.
 *   - Not run on a non-MySQL/MariaDB driver: absence of `information_schema` is
 *     absence of proof, and a constraint must never be assumed absent.
 *
 * OPERATIONAL PRECONDITIONS (DEV)
 *   1. Verified backup (§ docs/OPERATIONS.md §6) plus a restore test.
 *   2. Phase A has already run, so the surrounding warehouse/user protections are
 *      in place while this lock is held.
 *   3. No concurrent DDL: never run `migrate` in parallel with this plan.
 *   4. A quiet window (`php artisan down` / `up`) if writers must not see the lock.
 *
 * ROLLBACK LIMITATION
 *   The rebuild is destructive-in-place but fully reversible: the reverse is
 *   `ALTER TABLE villages DROP FOREIGN KEY villages_district_id_foreign`, which is
 *   metadata-only and instant. The rebuild does NOT change any `villages` column,
 *   default, index or row — the Phase B verification is therefore a row count and
 *   checksum comparison, not a data repair. `down()` is intentionally empty; see the
 * Phase A docblock for why, and docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md §5 for the
 * explicit inverse statement.
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
        ['villages', 'district_id', 'districts', 'id', 'cascade'],
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

    private function normalizeRestrictRule(string $rule): string
    {
        $rule = strtoupper(trim($rule));

        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
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
            && $this->normalizeRestrictRule((string) $rows[0]->delete_rule) === $this->normalizeRestrictRule($deleteRule)
            && $this->normalizeRestrictRule((string) $rows[0]->update_rule) === 'RESTRICT';
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
