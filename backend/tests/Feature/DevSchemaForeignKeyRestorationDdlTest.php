<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * DEV SCHEMA DRIFT REMEDIATION — the destructive half of the foreign-key restoration.
 *
 * These tests mutate the SCHEMA (MySQL DDL implies an implicit commit, so a dropped
 * constraint outlives a per-test transaction and would contaminate the rest of the
 * suite). They therefore use {@see RestoresIsolatedTestDatabase}, which rebuilds
 * `primeclassy_testing` from the migrations before and after each test and refuses to
 * run against any database other than the guarded isolated one.
 *
 * What is proved:
 *   1. the existence check reads `information_schema`, not the `migrations` table — the
 *      exact DEV failure mode, where `migrations` claims 128 applied migrations while
 *      97 constraints are absent;
 *   2. a restored constraint is really enforced (orphan insert -> errno 1452) and the
 *      phase is idempotent on rerun;
 *   3. `down()` cannot remove a protection, so a rollback can never silently undo the
 *      remediation;
 *   4. fail-closed preflight: an orphan row makes the WHOLE phase refuse with a precise
 *      report and **zero** mutations — never four constraints applied and the fifth
 *      aborting. This is the property the whole DEV sequencing depends on: Phase A/B
 *      must not partially apply because a later phase would refuse.
 *
 * @see DevSchemaForeignKeyRestorationTest
 */
class DevSchemaForeignKeyRestorationDdlTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    private const PHASE_A = '2026_10_08_110000_restore_pre_reset_foreign_keys';

    private const PHASE_C = '2026_10_08_112000_restore_post_cleanup_foreign_keys';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /* ───────────────────── information_schema, not migrations ───────────────────── */

    public function test_a_phase_finds_a_constraint_missing_even_though_the_migrations_table_records_it_as_applied(): void
    {
        $table = 'warehouse_settings';
        $name = "{$table}_agent_id_foreign";

        $this->assertTrue(Schema::hasTable($table));

        // The migrations that created this constraint are recorded as applied — that is
        // exactly the DEV situation, where migration history and schema disagree.
        $this->assertGreaterThan(
            0,
            DB::table('migrations')->count(),
            'The canonical migrations must be recorded, otherwise this test is not modelling the drift'
        );

        $this->assertTrue($this->constraintExists($table, $name));
        DB::statement("alter table `{$table}` drop foreign key `{$name}`");
        $this->assertFalse($this->constraintExists($table, $name));

        $this->migration(self::PHASE_A)->up();

        $this->assertTrue(
            $this->constraintExists($table, $name),
            'The phase must restore a constraint that is absent from the schema, whatever the migrations table claims'
        );
    }

    public function test_a_restored_constraint_is_enforced_and_rerunning_the_phase_changes_nothing(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $agent = $agent->fresh();

        $table = 'warehouse_sub_locations';
        $column = 'owner_user_id';
        $name = "{$table}_{$column}_foreign";

        DB::statement("alter table `{$table}` drop foreign key `{$name}`");
        $this->assertFalse($this->constraintExists($table, $name));

        $this->migration(self::PHASE_A)->up();
        $this->assertTrue($this->constraintExists($table, $name));

        // Enforcement, not just metadata: an orphan row must now be rejected by the database.
        $rejected = false;

        try {
            DB::statement(sprintf(
                "insert into `%s` (`agent_id`, `name`, `code`, `is_active`, `created_by`, `owner_user_id`) values (%d, 'FK', 'FK-RESTORE-PROBE', 1, %d, 999999999)",
                $table,
                $agent->id,
                $agent->id
            ));
        } catch (QueryException $e) {
            $rejected = str_contains($e->getMessage(), 'foreign key constraint fails');
        }

        $this->assertTrue($rejected, 'A restored constraint must actually reject an orphan row (errno 1452)');

        $afterRestore = $this->foreignKeyCount();

        // Rerun: idempotent, including immediately after the constraint was applied.
        $this->migration(self::PHASE_A)->up();
        $this->migration(self::PHASE_A)->up();

        $this->assertSame($afterRestore, $this->foreignKeyCount());

        // And a rollback must never remove the protection again.
        $this->migration(self::PHASE_A)->down();

        $this->assertTrue(
            $this->constraintExists($table, $name),
            'down() must be a no-op: these constraints are declared by the create-table migrations and a rollback must not drop them'
        );
    }

    /* ───────────────────────── fail-closed preflight ───────────────────────── */

    public function test_an_orphaned_row_makes_the_whole_phase_refuse_before_any_ddl(): void
    {
        $table = 'shipping_configurations';
        $column = 'shipping_provider_id';
        $name = "{$table}_{$column}_foreign";

        // Recreate the DEV condition for one phase-A constraint: the constraint is gone
        // and a row violates it. `agent_id` stays NULL so no other constraint on this
        // table is involved — the only blocker must be the one under test.
        DB::statement("alter table `{$table}` drop foreign key `{$name}`");
        $this->assertFalse($this->constraintExists($table, $name));

        DB::statement("insert into `{$table}` (`agent_id`, `{$column}`, `price_per_km`, `minimum_distance_km`) values (null, 999999999, 1000, 20)");

        $before = $this->foreignKeyCount();

        try {
            $this->migration(self::PHASE_A)->up();
            $this->fail('The phase must refuse while an orphan row exists');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                "{$table}.{$column} -> shipping_providers.id",
                $e->getMessage(),
                'The refusal must name the exact blocking constraint'
            );
            $this->assertStringContainsString('no constraint was changed', $e->getMessage());
        }

        $this->assertSame(
            $before,
            $this->foreignKeyCount(),
            'Fail-closed means zero mutations: not one of the other 90 constraints may be applied'
        );

        // The orphan must still be there for the operator to resolve — the migration
        // refuses, it never repairs data.
        $this->assertSame(1, (int) DB::selectOne(
            'select count(*) as c from `'.$table.'` where `'.$column.'` = 999999999'
        )->c);
    }

    public function test_phase_c_refuses_while_its_orphans_exist_and_adds_none_of_its_five(): void
    {
        $pairs = [
            ['shipping_configurations', 'agent_id', 'users'],
            ['user_closures', 'ancestor_id', 'users'],
            ['user_closures', 'descendant_id', 'users'],
            ['warehouse_migration_markers', 'product_id', 'products'],
            ['warehouse_migration_markers', 'product_variation_id', 'product_variations'],
        ];

        foreach ($pairs as [$table, $column]) {
            DB::statement("alter table `{$table}` drop foreign key `{$table}_{$column}_foreign`");
        }

        // Recreate one orphan per constraint, mirroring the audited DEV rows. Rows that
        // are NOT under test (marker agent_id/run_id) stay valid so the only blockers are
        // the five declared constraints.
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $agentId = $agent->fresh()->id;

        DB::statement("insert into `warehouse_migration_runs` (`run_id`, `dry_run`, `status`, `started_at`) values ('fk-restore-probe', 0, 'completed', now())");
        $runId = (int) DB::selectOne("select id from `warehouse_migration_runs` where `run_id` = 'fk-restore-probe'")->id;

        DB::statement('insert into `shipping_configurations` (`agent_id`, `shipping_provider_id`, `price_per_km`, `minimum_distance_km`) values (999999997, null, 1000, 20)');
        DB::statement('insert into `user_closures` (`ancestor_id`, `descendant_id`, `depth`) values (999999996, 1, 1)');
        DB::statement('insert into `user_closures` (`ancestor_id`, `descendant_id`, `depth`) values (1, 999999995, 1)');
        DB::statement("insert into `warehouse_migration_markers` (`agent_id`, `run_id`, `source_type`, `source_id`, `product_id`, `source_quantity`, `status`) values ({$agentId}, {$runId}, 'product_stock', 99001, 999999993, 1, 'migrated')");
        DB::statement("insert into `warehouse_migration_markers` (`agent_id`, `run_id`, `source_type`, `source_id`, `product_variation_id`, `source_quantity`, `status`) values ({$agentId}, {$runId}, 'product_variation_stock', 99002, 999999991, 1, 'migrated')");

        $before = $this->foreignKeyCount();

        try {
            $this->migration(self::PHASE_C)->up();
            $this->fail('Phase C must refuse while its orphan rows exist');
        } catch (RuntimeException $e) {
            foreach ($pairs as [$table, $column]) {
                $this->assertStringContainsString("{$table}.{$column}", $e->getMessage());
            }
        }

        $this->assertSame(
            $before,
            $this->foreignKeyCount(),
            'Phase C must add none of its five constraints while the cleanup has not happened'
        );

        // After the authorized cleanup the very same phase applies cleanly.
        DB::statement('delete from `shipping_configurations` where `agent_id` = 999999997');
        DB::statement('delete from `user_closures` where `ancestor_id` = 999999996 or `descendant_id` = 999999995');
        DB::statement('delete from `warehouse_migration_markers` where `run_id` = '.$runId);

        $this->migration(self::PHASE_C)->up();

        foreach ($pairs as [$table, $column]) {
            $this->assertTrue(
                $this->constraintExists($table, "{$table}_{$column}_foreign"),
                "{$table}.{$column} must be restorable once the orphan rows are gone"
            );
        }
    }

    public function test_a_missing_leading_index_is_refused_rather_than_silently_building_one(): void
    {
        $table = 'warehouse_settings';
        $column = 'agent_id';
        $constraint = "{$table}_{$column}_foreign";
        // warehouse_settings declares agent_id behind a UNIQUE index, not the usual
        // <table>_<column>_foreign index — exactly the case where an unindexed column
        // would make InnoDB build one nobody reviewed.
        $index = "{$table}_{$column}_unique";

        DB::statement("alter table `{$table}` drop foreign key `{$constraint}`");
        DB::statement("alter table `{$table}` drop index `{$index}`");

        $this->assertFalse($this->constraintExists($table, $constraint));

        try {
            $this->migration(self::PHASE_A)->up();
            $this->fail('The phase must refuse rather than build an index nobody reviewed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no existing index starts on', $e->getMessage());
            $this->assertStringContainsString("{$table}.{$column}", $e->getMessage());
        }

        $this->assertFalse($this->constraintExists($table, $constraint));
    }

    public function test_a_same_named_foreign_key_with_different_rules_is_refused_unchanged(): void
    {
        $table = 'warehouse_settings';
        $column = 'agent_id';
        $name = "{$table}_{$column}_foreign";

        DB::statement("alter table `{$table}` drop foreign key `{$name}`");
        DB::statement("alter table `{$table}` add constraint `{$name}` foreign key (`{$column}`) references `users` (`id`) on delete cascade on update restrict");
        $before = $this->foreignKeyCount();

        try {
            $this->migration(self::PHASE_A)->up();
            $this->fail('A same-named constraint with a different delete rule must fail closed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different definition', $e->getMessage());
            $this->assertStringContainsString("{$table}.{$column}", $e->getMessage());
        }

        $this->assertSame($before, $this->foreignKeyCount());
        $row = DB::selectOne(
            'select delete_rule from information_schema.referential_constraints where constraint_schema = ? and table_name = ? and constraint_name = ?',
            [DB::getDatabaseName(), $table, $name]
        );
        $this->assertSame('CASCADE', strtoupper((string) $row->delete_rule));
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function migration(string $phase): object
    {
        return require database_path("migrations/{$phase}.php");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.referential_constraints
              where constraint_schema = ? and table_name = ? and constraint_name = ?',
            [DB::getDatabaseName(), $table, $constraint]
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
