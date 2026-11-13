<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * REM-06 DB hardening: validates the REM-06 migration (database/migrations/, authorised for DEV) on the disposable schema only.
 * The standard test schema already contains it, so setUp() runs down() to recreate the pre-migration state for the up() scenarios.
 * Committed DDL — runs through RestoresIsolatedTestDatabase, which rebuilds the schema afterwards.
 */
class CategoryUniqueKeyMigrationTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected bool $isolatedSchemaAlwaysRebuild = true;

    private const FILE = 'database/migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php';

    private bool $standardSchemaHadHardening = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->standardSchemaHadHardening = Schema::hasColumn('product_categories', 'name_key');
        if ($this->standardSchemaHadHardening) {
            $this->migration()->down();
        }
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function legacy(string $name, ?int $parent = null): int
    {
        return DB::table('product_categories')->insertGetId(['name' => $name, 'parent_id' => $parent, 'slug' => 's-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function raw(?int $parent, string $name, string $key): bool
    {
        return DB::table('product_categories')->insert(['parent_id' => $parent, 'name' => $name, 'name_key' => $key, 'slug' => 's-'.uniqid()]);
    }

    private function rejects(callable $fn, int $driverCode = 1062): void
    {
        try {
            $fn();
            $this->fail('expected the database to reject the statement');
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [$driverCode, 3819, 4025, 1364, 1406], $e->getMessage());
        }
    }

    private function createStatement(): string
    {
        return (string) collect((array) DB::selectOne('SHOW CREATE TABLE product_categories'))->last();
    }

    public function test_the_standard_schema_contains_the_hardening_via_the_official_migration(): void
    {
        $this->assertFileExists(base_path(self::FILE));
        $this->assertTrue($this->standardSchemaHadHardening, 'migrate (fresh test schema) must create name_key from the official migration');
        $this->assertFalse(Schema::hasColumn('product_categories', 'name_key'), 'setUp() down() restored the pre-migration shape for the up() scenarios');
    }

    public function test_up_fails_closed_on_legacy_duplicates_before_any_ddl_and_never_touches_data(): void
    {
        $root = $this->legacy('Minuman');
        $this->legacy(' minuman ');                                  // root duplicate (case/whitespace)
        $kue = $this->legacy('Kue');
        $this->legacy('Nastar', $kue);
        $this->legacy("Cafe\u{0301}", $kue);
        $this->legacy("Caf\u{00E9}", $kue);                          // NFD vs NFC under the same parent
        $this->legacy("Teh\u{200B}", $kue);
        $this->legacy('Teh', $kue);                                  // zero-width
        $before = DB::table('product_categories')->orderBy('id')->get()->toArray();
        $schema = $this->createStatement();

        try {
            $this->migration()->up();
            $this->fail('the migration must abort');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ABORTED before any DDL', $e->getMessage());
            $this->assertStringContainsString('3 duplicate group(s)', $e->getMessage());
            $this->assertStringNotContainsString('inuman', $e->getMessage(), 'names are not echoed');
            $this->assertStringContainsString((string) $root, $e->getMessage());
        }
        $this->assertSame($schema, $this->createStatement(), 'no DDL happened');
        $this->assertEquals($before, DB::table('product_categories')->orderBy('id')->get()->toArray(), 'no data changed');
        $this->assertSame(1, (int) DB::selectOne("SELECT IS_FREE_LOCK('primeclassy:product_categories:create') AS f")->f, 'lock released after abort');
    }

    public function test_up_fails_closed_on_an_empty_normalised_name(): void
    {
        $this->legacy("\u{200B}\t ");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty-name');
        $this->migration()->up();
    }

    public function test_up_on_clean_data_backfills_keys_adds_constraints_and_preserves_every_row(): void
    {
        $kue = $this->legacy('Kue');
        $this->legacy('  Kue   Kering ', $kue);                       // legacy un-normalised name must be kept as is
        $this->legacy('Nastar', $kue);
        $roti = $this->legacy('Roti');
        $this->legacy('Nastar', $roti);                              // same name, different parent
        $this->legacy('Nastar');                                     // root vs child
        $before = DB::table('product_categories')->orderBy('id')->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'sort_order'])->toArray();

        $this->migration()->up();

        $this->assertEquals($before, DB::table('product_categories')->orderBy('id')->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'sort_order'])->toArray(), 'categories untouched');
        $keys = DB::table('product_categories')->orderBy('id')->pluck('name_key', 'name')->all();
        $this->assertSame('kue kering', DB::table('product_categories')->where('name', '  Kue   Kering ')->value('name_key'));
        $this->assertSame('nastar', $keys['Nastar']);
        $this->assertTrue(Schema::hasColumn('product_categories', 'root_name_key'));
        $indexes = collect(DB::select('SHOW INDEX FROM product_categories'))->where('Non_unique', 0)->pluck('Key_name')->unique()->values()->all();
        foreach (['uq_product_categories_parent_name_key', 'uq_product_categories_root_name_key', 'product_categories_slug_unique'] as $i) {
            $this->assertContains($i, $indexes);
        }
        $this->assertStringContainsString('ON DELETE SET NULL', $this->createStatement(), 'existing foreign keys intact');
        $this->migration()->up();                                    // idempotent
        $this->assertSame(count($before), DB::table('product_categories')->count());
    }

    public function test_direct_sql_cannot_create_root_or_sibling_duplicates_and_allows_legitimate_names(): void
    {
        $this->migration()->up();
        $p1 = DB::table('product_categories')->insertGetId(['name' => 'Kue', 'name_key' => 'kue', 'slug' => 'kue']);
        $p2 = DB::table('product_categories')->insertGetId(['name' => 'Roti', 'name_key' => 'roti', 'slug' => 'roti']);

        $this->raw($p1, 'Nastar', 'nastar');
        $this->rejects(fn () => $this->raw($p1, 'NASTAR', 'nastar'));            // sibling duplicate
        $this->rejects(fn () => $this->raw(null, 'KUE', 'kue'));                 // ROOT duplicate (NULL parent) — the classic UNIQUE(parent,name) hole
        $this->assertTrue($this->raw($p2, 'Nastar', 'nastar'));                  // same name, different parent
        $this->assertTrue($this->raw(null, 'Nastar', 'nastar'));                 // root vs child
        $this->assertTrue($this->raw($p1, 'Cafe', 'cafe') && $this->raw($p1, 'Café', 'café'), 'accent variants stay distinct (application semantics)');
        $this->assertTrue($this->raw($p1, str_repeat('é', 100), str_repeat('é', 100)));
        $this->assertTrue($this->raw($p1, 'L', str_repeat('é', 255)), '255 characters are stored without truncation');
        $this->rejects(fn () => $this->raw($p1, 'L2', str_repeat('é', 255).'x'), 1406);   // never truncated into a collision
    }

    public function test_a_raw_writer_that_omits_or_blanks_the_key_fails_closed_even_in_non_strict_mode(): void
    {
        $this->migration()->up();
        $this->rejects(fn () => DB::table('product_categories')->insert(['name' => 'Tanpa Key', 'slug' => 'tk']), 1364);
        DB::statement("SET SESSION sql_mode = ''");                                  // lenient session: omitted key would default to ''
        try {
            $this->rejects(fn () => DB::table('product_categories')->insert(['name' => 'Tanpa Key', 'slug' => 'tk2']), 4025);
            $this->rejects(fn () => $this->raw(null, 'Kosong', ''), 4025);
        } finally {
            DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        $this->assertSame(0, DB::table('product_categories')->count());
    }

    public function test_residual_risk_a_raw_writer_that_fills_a_differen_t_key_for_the_same_name_is_not_stopped(): void
    {
        $this->migration()->up();
        $this->raw(null, 'Minuman', 'minuman');
        $this->assertTrue($this->raw(null, 'Minuman', 'MINUMAN-2'), 'documented limitation: the database cannot recompute the application key');
        $this->assertSame(2, DB::table('product_categories')->count());
    }

    public function test_application_stays_compatible_after_the_migration_api_eloquent_rename_reparent_and_delete(): void
    {
        $this->seed(RoleSeeder::class);
        $this->migration()->up();
        ProductCategory::forgetSchemaMemo();
        $sa = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        // Eloquent (any writer, incl. seeders/tests) gets the key automatically
        $viaModel = ProductCategory::create(['name' => '  Es   Teh ', 'slug' => 'es-teh']);
        $this->assertSame('es teh', $viaModel->refresh()->name_key);

        // API: create-only role, duplicates still a clean 422 (not a 500), different parent ok
        $kue = $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Kue'])->assertCreated()->json('data.id');
        $roti = $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Roti'])->assertCreated()->json('data.id');
        $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'KUE'])->assertStatus(422)->assertJsonValidationErrors('name');
        $n1 = $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Nastar', 'parent_id' => $kue])->assertCreated()->json('data.id');
        $n2 = $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Nastar', 'parent_id' => $roti])->assertCreated()->json('data.id');
        $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Nastar', 'parent_id' => $kue])->assertStatus(422);
        $this->actingAs($agen)->patchJson("/api/v1/categories/{$n1}", ['name' => 'x'])->assertForbidden();           // create-only unchanged

        // super_admin: rename / reparent into a duplicate → 422 (never a database error), and the key follows a rename
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$n2}", ['parent_id' => $kue])->assertStatus(422);
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$n2}", ['name' => 'nastar '])->assertOk();             // same key, same parent as itself: allowed
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$n2}", ['name' => 'Kastengel'])->assertOk();
        $this->assertSame('kastengel', DB::table('product_categories')->where('id', $n2)->value('name_key'));
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$n2}", ['parent_id' => $kue])->assertOk();               // no longer a duplicate
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$n1}", ['parent_id' => null])->assertOk();              // detach to root: no root "Nastar" yet

        // delete: a parent whose child would be promoted into a colliding root is refused (409), nothing changes
        $a = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Induk A'])->assertCreated()->json('data.id');
        $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Nastar', 'parent_id' => $a])->assertCreated();   // root "Nastar" exists (n1) → child collides on promotion
        $this->actingAs($sa)->deleteJson("/api/v1/categories/{$a}")->assertStatus(409);
        $this->assertTrue(ProductCategory::whereKey($a)->exists());
        $this->assertSame(1, ProductCategory::where('parent_id', $a)->count());
        // …but a parent without a colliding child deletes as before
        $solo = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Sendirian'])->assertCreated()->json('data.id');
        $this->actingAs($sa)->deleteJson("/api/v1/categories/{$solo}")->assertOk();

        // audit trail unchanged
        $this->assertGreaterThanOrEqual(3, ActivityLog::where('event', 'category.create')->count());
    }

    public function test_a_stale_absent_column_answer_never_survives_the_migration_in_a_long_lived_process(): void
    {
        $this->seed(RoleSeeder::class);
        ProductCategory::forgetSchemaMemo();
        // pre-migration: the code works without the column (deployable BEFORE the migration)…
        $this->assertFalse(ProductCategory::nameKeyColumnExists());
        ProductCategory::create(['name' => 'Sebelum', 'slug' => 'sebelum']);
        // …then the migration runs while this same process stays alive (queue worker / test process). NO forgetSchemaMemo().
        $this->migration()->up();
        $this->assertTrue(ProductCategory::nameKeyColumnExists());
        $created = ProductCategory::create(['name' => '  Sesudah  ', 'slug' => 'sesudah']);
        $this->assertSame('sesudah', $created->refresh()->name_key);
    }

    public function test_a_duplicate_that_slips_past_the_application_check_is_a_database_error_for_eloquent_writers_but_422_for_the_service(): void
    {
        $this->seed(RoleSeeder::class);
        $this->migration()->up();
        ProductCategory::forgetSchemaMemo();
        ProductCategory::create(['name' => 'Kue', 'slug' => 'kue']);
        try {
            ProductCategory::create(['name' => 'KUE', 'slug' => 'kue-2']);   // bypasses the service entirely
            $this->fail('the database must refuse');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        $this->assertSame(1, ProductCategory::count());
    }

    public function test_down_restores_the_original_schema_exactly_and_is_repeatable(): void
    {
        $this->legacy('Kue');
        $original = $this->createStatement();
        $this->migration()->up();
        $this->assertNotSame($original, $this->createStatement());
        $this->migration()->down();
        $this->assertSame($original, $this->createStatement());
        $this->assertSame(1, DB::table('product_categories')->count());
        $this->migration()->down();                                  // tolerant of an already rolled-back / partial state
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame($original, $this->createStatement());
        $this->assertSame('Kue', DB::table('product_categories')->value('name'));
    }

    public function test_two_real_connections_inserting_the_same_key_directly_yield_exactly_one_row(): void
    {
        $this->seed(RoleSeeder::class);
        $a1 = User::factory()->agen()->create();
        $a1->update(['agent_id' => $a1->id]);
        $this->migration()->up();
        DB::commit(); // make sure fixtures + DDL are visible to the second PHP process

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'category-raw-insert', 'actor_id' => $a1->id, 'extra' => ['name' => 'Serentak', 'name_key' => 'serentak', 'slug' => 'serentak-a']],
            ['op' => 'category-raw-insert', 'actor_id' => $a1->id, 'extra' => ['name' => 'SERENTAK', 'name_key' => 'serentak', 'slug' => 'serentak-b']],
        );

        $this->assertTrue($report['true_overlap']);
        $sides = collect([$report['a'], $report['b']]);
        $this->assertSame(1, $sides->where('outcome', 'success')->count(), json_encode($report));
        $loser = $sides->firstWhere('outcome', '!=', 'success');
        $this->assertSame('23000', (string) ($loser['sql_state'] ?? ''), json_encode($loser));
        $this->assertSame(1062, (int) ($loser['error_code'] ?? 0));
        $this->assertSame(1, DB::table('product_categories')->where('name_key', 'serentak')->count());
    }

    public function test_service_races_still_converge_to_one_row_with_the_constraint_in_place(): void
    {
        $this->seed(RoleSeeder::class);
        $a1 = User::factory()->agen()->create();
        $a1->update(['agent_id' => $a1->id]);
        $a2 = User::factory()->agen()->create();
        $a2->update(['agent_id' => $a2->id]);
        $this->migration()->up();
        DB::commit();

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'category-create', 'actor_id' => $a1->id, 'extra' => ['name' => 'Minuman']],
            ['op' => 'category-create', 'actor_id' => $a2->id, 'extra' => ['name' => '  minuman ']],
        );

        $sides = collect([$report['a'], $report['b']]);
        $this->assertSame(1, $sides->where('outcome', 'success')->count(), json_encode($report));
        $this->assertSame(ValidationException::class, $sides->firstWhere('outcome', '!=', 'success')['exception'] ?? null);
        $this->assertSame(1, DB::table('product_categories')->whereNull('parent_id')->count());
    }
}
