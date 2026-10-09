<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV SCHEMA DRIFT REMEDIATION — the three foreign-key restoration migrations,
 * verified against the FULLY MIGRATED test schema (`primeclassy_testing`).
 *
 * `primeclassy_testing` is the reference implementation of the migrations, so it
 * carries all 204 declared constraints while `primeclassy_dev` carried 107. That
 * makes it the right place to prove three things that cannot be proven on DEV
 * without a destructive ALTER:
 *
 *   1. the constraint lists are a FAITHFUL COPY of what the migrations declare —
 *      every name, referenced table/column and ON DELETE / ON UPDATE rule matches
 *      the schema the create-table migrations actually produce, so no entry can be
 *      a transcription slip;
 *   2. each phase is a strict, non-overlapping partition of the 97 missing
 *      constraints, split by "restorable now" vs "blocked by orphans" vs "own table
 *      rebuild";
 *   3. each phase is a genuine NO-OP here — the existence check reads
 *      `information_schema`, not the `migrations` table, so all three are inert on a
 *      correctly migrated database and safe to rerun.
 *
 * The destructive scenarios (dropping a constraint, planting an orphan row, proving
 * fail-closed DDL behaviour) live in DevSchemaForeignKeyRestorationDdlTest, which
 * rebuilds the isolated schema around itself.
 *
 * @see DevSchemaForeignKeyRestorationDdlTest
 */
class DevSchemaForeignKeyRestorationTest extends TestCase
{
    use RefreshDatabase;

    private const PHASE_A = '2026_10_08_110000_restore_pre_reset_foreign_keys';

    private const PHASE_B = '2026_10_08_111000_restore_villages_district_foreign_key';

    private const PHASE_C = '2026_10_08_112000_restore_post_cleanup_foreign_keys';

    /** The 5 constraints blocked by orphan rows on the audited DEV baseline. */
    private const ORPHAN_BLOCKED = [
        'shipping_configurations.agent_id',
        'user_closures.ancestor_id',
        'user_closures.descendant_id',
        'warehouse_migration_markers.product_id',
        'warehouse_migration_markers.product_variation_id',
    ];

    /* ─────────────────────────── declared lists ─────────────────────────── */

    public function test_the_three_phases_partition_the_whole_missing_constraint_set(): void
    {
        $phaseA = $this->keys($this->constraintsOf(self::PHASE_A));
        $phaseB = $this->keys($this->constraintsOf(self::PHASE_B));
        $phaseC = $this->keys($this->constraintsOf(self::PHASE_C));

        $this->assertCount(91, $phaseA, 'Phase A must hold exactly the 91 constraints with zero orphans');
        $this->assertSame(['villages.district_id'], $phaseB, 'Phase B must isolate the single table-rebuild constraint');
        $this->assertSame(self::ORPHAN_BLOCKED, $phaseC, 'Phase C must hold exactly the 5 orphan-blocked constraints');

        $all = array_merge($phaseA, $phaseB, $phaseC);
        $this->assertCount(97, $all, 'The three phases must cover the 97 missing constraints');
        $this->assertSame($all, array_values(array_unique($all)), 'No constraint may appear in two phases');

        // Nothing orphan-blocked may leak into a phase that runs before the cleanup.
        $this->assertSame([], array_intersect($phaseA, self::ORPHAN_BLOCKED));
        $this->assertSame([], array_intersect($phaseB, self::ORPHAN_BLOCKED));
    }

    /**
     * The restoration must re-create each constraint under the exact name the
     * create-table migrations gave it. A different name would be a second, parallel
     * constraint over the same column and would leave the canonical schema and DEV
     * permanently divergent in name as well as in enforcement.
     */
    public function test_the_generated_constraint_name_matches_the_one_the_create_migrations_produced(): void
    {
        $divergent = [];

        foreach ($this->allConstraints() as [$phase, $table, $column]) {
            $row = DB::selectOne(
                'select kcu.constraint_name as constraint_name
                   from information_schema.key_column_usage kcu
                   join information_schema.referential_constraints rc
                     on rc.constraint_schema = kcu.constraint_schema
                    and rc.table_name = kcu.table_name
                    and rc.constraint_name = kcu.constraint_name
                  where kcu.constraint_schema = ? and kcu.table_name = ? and kcu.column_name = ?
                    and kcu.referenced_table_name is not null',
                [DB::getDatabaseName(), $table, $column]
            );

            $expected = "{$table}_{$column}_foreign";

            if ($row === null) {
                $divergent[] = "[{$phase}] {$table}.{$column}: the canonical schema declares no constraint at all";

                continue;
            }

            if ((string) $row->constraint_name !== $expected) {
                $divergent[] = "[{$phase}] {$table}.{$column}: canonical name is `{$row->constraint_name}`, restoration would use `{$expected}`";
            }
        }

        $this->assertSame([], $divergent);
    }

    /* ─────────────────── the canonical reference schema ─────────────────── */

    public function test_every_declared_constraint_already_exists_on_the_fully_migrated_schema(): void
    {
        $missing = [];
        $mismatched = [];

        foreach ($this->allConstraints() as [$phase, $table, $column, $parent, $parentColumn, $deleteRule]) {
            $actual = $this->canonicalConstraint($table, "{$table}_{$column}_foreign");

            if ($actual === null) {
                $missing[] = "[{$phase}] {$table}.{$column}";

                continue;
            }

            // Laravel emits no onUpdate anywhere in this repository, so every declared
            // constraint inherits the MySQL default RESTRICT on UPDATE.
            if ($actual['referenced_table'] !== $parent
                || $actual['referenced_column'] !== $parentColumn
                || $actual['delete_rule'] !== strtoupper($deleteRule)
                || $actual['update_rule'] !== 'RESTRICT') {
                $mismatched[] = sprintf(
                    '[%s] %s.%s -> expected %s.%s / %s / RESTRICT, canonical schema has %s.%s / %s / %s',
                    $phase,
                    $table,
                    $column,
                    $parent,
                    $parentColumn,
                    strtoupper($deleteRule),
                    $actual['referenced_table'],
                    $actual['referenced_column'],
                    $actual['delete_rule'],
                    $actual['update_rule']
                );
            }
        }

        $this->assertSame([], $missing, 'Every declared constraint must exist on the canonical migrated schema');
        $this->assertSame([], $mismatched, 'Every declared constraint must match the canonical schema exactly');
    }

    public function test_every_declared_constraint_would_reuse_an_existing_index(): void
    {
        $problems = [];

        foreach ($this->allConstraints() as [$phase, $table, $column, $parent, $parentColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $problems[] = "[{$phase}] {$table}.{$column}: missing child table or column";

                continue;
            }

            if (! Schema::hasTable($parent) || ! Schema::hasColumn($parent, $parentColumn)) {
                $problems[] = "[{$phase}] {$table}.{$column} -> {$parent}.{$parentColumn}: missing referenced table or column";

                continue;
            }

            if ($this->columnType($table, $column) !== $this->columnType($parent, $parentColumn)) {
                $problems[] = "[{$phase}] {$table}.{$column}: column type does not match {$parent}.{$parentColumn}";
            }

            if (! $this->hasLeadingIndex($table, $column)) {
                $problems[] = "[{$phase}] {$table}.{$column}: no existing index starts on the column, so the ADD CONSTRAINT would build one";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_no_declared_column_holds_an_orphan_row_on_the_canonical_schema(): void
    {
        $orphans = [];

        foreach ($this->allConstraints() as [$phase, $table, $column, $parent, $parentColumn]) {
            $row = DB::selectOne(sprintf(
                'select exists(select 1 from `%s` where `%s` is not null and `%s` not in (select `%s` from `%s`) limit 1) as orphan_exists',
                $table,
                $column,
                $column,
                $parentColumn,
                $parent
            ));

            if ($row !== null && (int) $row->orphan_exists === 1) {
                $orphans[] = "[{$phase}] {$table}.{$column} -> {$parent}.{$parentColumn}";
            }
        }

        $this->assertSame([], $orphans);
    }

    /* ─────────────────────────── idempotency ─────────────────────────── */

    public function test_each_phase_is_a_no_op_on_a_fully_migrated_schema(): void
    {
        $before = $this->foreignKeyCount();

        foreach ([self::PHASE_A, self::PHASE_B, self::PHASE_C] as $phase) {
            $this->migration($phase)->up();
        }

        $this->assertSame(
            $before,
            $this->foreignKeyCount(),
            'Running all three phases on a correctly migrated schema must not add a single constraint'
        );

        // Rerunning must stay inert as well — that is the property the DEV remediation
        // depends on after a partial failure.
        foreach ([self::PHASE_A, self::PHASE_B, self::PHASE_C] as $phase) {
            $this->migration($phase)->up();
        }

        $this->assertSame($before, $this->foreignKeyCount());
    }

    /* ─────────────────────────── forbidden mechanisms ─────────────────────────── */

    public function test_the_migrations_never_weaken_or_bypass_a_constraint(): void
    {
        $forbidden = [
            'disableForeignKeyConstraints',
            'enableForeignKeyConstraints',
            'foreign_key_checks',
            'Schema::dropIfExists',
            'Schema::drop',
            'migrate:fresh',
            'migrate:reset',
            'db:wipe',
            'truncate',
            'delete(',
            'drop table',
        ];

        foreach ([self::PHASE_A, self::PHASE_B, self::PHASE_C] as $phase) {
            // Comments and docblocks are stripped first: the migrations *describe* the
            // mechanisms they refuse to use, and that prose must not be mistaken for code.
            $code = (string) php_strip_whitespace($this->migrationPath($phase));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $code,
                    "{$phase} must not use {$needle}: an existing row may never be made to pass a constraint by bypassing it"
                );
            }

            $this->assertStringContainsString('add constraint', $code, "{$phase} must add constraints explicitly");
            $this->assertStringContainsString('on update restrict', $code, "{$phase} must pin ON UPDATE instead of relying on a server default");
        }
    }

    public function test_each_phase_declares_an_empty_down_so_a_rollback_cannot_remove_a_protection(): void
    {
        foreach ([self::PHASE_A, self::PHASE_B, self::PHASE_C] as $phase) {
            $down = new ReflectionMethod($this->migration($phase), 'down');

            $this->assertStringContainsString(
                'intentionally empty',
                (string) $down->getDocComment(),
                "{$phase} must document why down() is empty instead of leaving it unexplained"
            );

            $before = $this->foreignKeyCount();
            $this->migration($phase)->down();

            $this->assertSame(
                $before,
                $this->foreignKeyCount(),
                "{$phase}->down() must not drop a constraint the original create-table migrations declare"
            );
        }
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function migration(string $phase): object
    {
        return require database_path("migrations/{$phase}.php");
    }

    private function migrationPath(string $phase): string
    {
        return database_path("migrations/{$phase}.php");
    }

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private function constraintsOf(string $phase): array
    {
        return (new ReflectionClass($this->migration($phase)))->getConstant('CONSTRAINTS');
    }

    /**
     * Every declared constraint of every phase.
     *
     * @return array<int, array<int, string>>
     */
    private function allConstraints(): array
    {
        $all = [];

        foreach ([self::PHASE_A, self::PHASE_B, self::PHASE_C] as $phase) {
            foreach ($this->constraintsOf($phase) as [$table, $column, $parent, $parentColumn, $deleteRule]) {
                $all[] = [$phase, $table, $column, $parent, $parentColumn, $deleteRule];
            }
        }

        return $all;
    }

    /** @return array<int, string> */
    private function keys(array $constraints): array
    {
        return array_map(fn (array $c): string => $c[0].'.'.$c[1], $constraints);
    }

    /** @return array{referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string}|null */
    private function canonicalConstraint(string $table, string $constraint): ?array
    {
        $row = DB::selectOne(
            'select kcu.referenced_table_name as referenced_table,
                    kcu.referenced_column_name as referenced_column,
                    rc.delete_rule as delete_rule,
                    rc.update_rule as update_rule
               from information_schema.key_column_usage kcu
               join information_schema.referential_constraints rc
                 on rc.constraint_schema = kcu.constraint_schema
                and rc.table_name = kcu.table_name
                and rc.constraint_name = kcu.constraint_name
              where kcu.constraint_schema = ? and kcu.table_name = ? and kcu.constraint_name = ?',
            [DB::getDatabaseName(), $table, $constraint]
        );

        return $row === null ? null : [
            'referenced_table' => (string) $row->referenced_table,
            'referenced_column' => (string) $row->referenced_column,
            'delete_rule' => (string) $row->delete_rule,
            'update_rule' => (string) $row->update_rule,
        ];
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select column_type from information_schema.columns
              where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        return $row === null ? null : strtolower((string) $row->column_type);
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
              where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1 limit 1',
            [DB::getDatabaseName(), $table, $column]
        ) !== null;
    }

    private function foreignKeyCount(): int
    {
        return (int) DB::selectOne(
            'select count(*) as c from information_schema.referential_constraints where constraint_schema = ?',
            [DB::getDatabaseName()]
        )->c;
    }
}
