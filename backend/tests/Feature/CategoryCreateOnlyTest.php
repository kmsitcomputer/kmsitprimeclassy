<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Product\CategoryHierarchyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REM-06 final (Owner 13 Nov 2026): the GLOBAL category taxonomy is CREATE-ONLY for Admin Agen and Agen.
 * Edit / delete / reparent stay super_admin-only and are enforced on the backend. Real database; no mocks.
 */
class CategoryCreateOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function agen(string $name = 'Agen'): User
    {
        $a = User::factory()->agen()->create(['name' => $name]);
        $a->update(['agent_id' => $a->id]);

        return $a;
    }

    private function admin(User $agen): User
    {
        return User::factory()->admin()->create(['agent_id' => $agen->id]);
    }

    private function create(User $u, array $payload)
    {
        return $this->actingAs($u)->postJson('/api/v1/categories', $payload);
    }

    // ------------------------------------------------------------------ authorization ---

    public function test_admin_and_agen_can_create_parent_and_child_in_the_global_taxonomy(): void
    {
        $agen = $this->agen();
        $admin = $this->admin($agen);

        $rootA = $this->create($admin, ['name' => 'Minuman'])->assertCreated()->json('data');
        $childA = $this->create($admin, ['name' => 'Kopi', 'parent_id' => $rootA['id']])->assertCreated()->json('data');
        $rootB = $this->create($agen, ['name' => 'Camilan'])->assertCreated()->json('data');
        $childB = $this->create($agen, ['name' => 'Keripik', 'parent_id' => $rootB['id']])->assertCreated()->json('data');

        $this->assertNull($rootA['parent_id']);
        $this->assertSame($rootA['id'], $childA['parent_id']);
        $this->assertSame($rootB['id'], $childB['parent_id']);
        // immediately part of the ONE global taxonomy, readable by anyone (public read unchanged)
        $names = collect($this->getJson('/api/v1/categories')->assertOk()->json('data'))->pluck('name')->all();
        foreach (['Minuman', 'Kopi', 'Camilan', 'Keripik'] as $n) {
            $this->assertContains($n, $names);
        }
        // a different network's agen can use a category created by this one (global, no ownership column)
        $other = $this->agen('Agen Lain');
        $product = Product::create(['name' => 'P', 'slug' => 'p', 'sku' => 'P-1', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $this->actingAs($other)->patchJson("/api/v1/products/{$product->id}", ['category_id' => $childA['id']])->assertOk();
        $this->assertSame($childA['id'], $product->refresh()->category_id);
    }

    public function test_super_admin_keeps_full_control(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $a = $this->create($sa, ['name' => 'A', 'sort_order' => 5, 'is_active' => false])->assertCreated()->json('data');
        $b = $this->create($sa, ['name' => 'B'])->assertCreated()->json('data');
        $this->assertSame(5, $a['sort_order']);
        $this->assertFalse($a['is_active']);
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$b['id']}", ['parent_id' => $a['id'], 'name' => 'B2'])->assertOk();
        $this->actingAs($sa)->deleteJson("/api/v1/categories/{$b['id']}")->assertOk();
    }

    public function test_admin_and_agen_cannot_edit_rename_reparent_or_delete_on_the_backend(): void
    {
        $agen = $this->agen();
        $admin = $this->admin($agen);
        $root = ProductCategory::create(['name' => 'Root', 'slug' => 'root']);
        $other = ProductCategory::create(['name' => 'Other', 'slug' => 'other']);
        $child = ProductCategory::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);

        foreach ([$agen, $admin] as $u) {
            $this->actingAs($u)->patchJson("/api/v1/categories/{$root->id}", ['name' => 'Hacked'])->assertForbidden();
            $this->actingAs($u)->patchJson("/api/v1/categories/{$child->id}", ['parent_id' => $other->id])->assertForbidden();   // reparent
            $this->actingAs($u)->patchJson("/api/v1/categories/{$child->id}", ['parent_id' => null])->assertForbidden();           // detach
            $this->actingAs($u)->patchJson("/api/v1/categories/{$root->id}", ['is_active' => false, 'sort_order' => 0])->assertForbidden();
            $this->actingAs($u)->deleteJson("/api/v1/categories/{$child->id}")->assertForbidden();
        }
        $this->assertSame('Root', $root->refresh()->name);
        $this->assertSame($root->id, $child->refresh()->parent_id);
        $this->assertSame(3, ProductCategory::count());
        $this->assertTrue($root->is_active);
    }

    public function test_other_roles_cannot_create(): void
    {
        $agen = $this->agen();
        $users = [
            User::factory()->konsumen()->create(), User::factory()->keuangan()->create(['agent_id' => $agen->id]), User::factory()->gudang()->create(['agent_id' => $agen->id]),
            User::factory()->kurir()->create(['agent_id' => $agen->id]), User::factory()->sales()->create(['agent_id' => $agen->id]), User::factory()->korsal()->create(['agent_id' => $agen->id]),
            User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id]), User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]),
        ];
        foreach ($users as $u) {
            $this->create($u, ['name' => 'X'])->assertForbidden();
        }
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_anonymous_create_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/categories', ['name' => 'X'])->assertUnauthorized();
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_forged_payload_cannot_raise_privileges_or_influence_ordering_visibility_or_slug(): void
    {
        $agen = $this->agen();
        $r = $this->create($agen, [
            'name' => 'Forged', 'role' => 'super_admin', 'role_id' => 1, 'actor_id' => 999, 'agent_id' => 12345, 'created_by' => 1,
            'sort_order' => 0, 'is_active' => false, 'slug' => 'admin-owned-slug',
        ])->assertCreated()->json('data');
        $this->assertTrue($r['is_active'], 'create-only role cannot hide a global category');
        $this->assertSame(0, $r['sort_order']);
        $this->assertNotSame('admin-owned-slug', $r['slug']);
        $this->assertStringStartsWith('forged-', $r['slug']);
        // …and the same role is still denied the privileged operations afterwards
        $this->actingAs($agen)->patchJson("/api/v1/categories/{$r['id']}", ['name' => 'x'])->assertForbidden();
        $audit = ActivityLog::where('event', 'category.create')->sole();
        $this->assertSame($agen->id, (int) $audit->causer_id);   // actor = authenticated user, not payload 999
    }

    public function test_parent_validation_missing_non_integer_and_nonexistent_are_rejected(): void
    {
        $agen = $this->agen();
        $this->create($agen, ['name' => 'X', 'parent_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->create($agen, ['name' => 'X', 'parent_id' => 'abc'])->assertStatus(422);
        $this->create($agen, ['parent_id' => null])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->create($agen, ['name' => str_repeat('a', 101)])->assertStatus(422);
        $this->create($agen, ['name' => " \t\u{200B} "])->assertStatus(422);          // empty after normalisation
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_create_cannot_produce_self_parent_or_cycles_and_reparent_stays_cycle_safe_for_super_admin(): void
    {
        $agen = $this->agen();
        $sa = User::factory()->superAdmin()->create();
        $a = $this->create($agen, ['name' => 'A'])->assertCreated()->json('data.id');
        $b = $this->create($agen, ['name' => 'B', 'parent_id' => $a])->assertCreated()->json('data.id');
        // a brand-new row can only point at an already existing parent → no self/cycle possible via create
        $this->assertNotSame($b, ProductCategory::find($b)->parent_id);
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$a}", ['parent_id' => $b])->assertStatus(422); // existing guard intact
    }

    // ------------------------------------------------------------------ deduplication ---

    public function test_duplicate_name_under_the_same_parent_is_rejected_for_every_equivalent_spelling(): void
    {
        $agen = $this->agen();
        $parent = $this->create($agen, ['name' => 'Kue'])->assertCreated()->json('data.id');
        $this->create($agen, ['name' => 'Kopi Susu', 'parent_id' => $parent])->assertCreated();

        foreach (['kopi susu', 'KOPI SUSU', '  Kopi   Susu  ', "Kopi\tSusu", "Kopi\u{00A0}Susu", "Kopi\u{200B} Susu", 'Kopi Susu '] as $variant) {
            $r = $this->create($this->admin($agen), ['name' => $variant, 'parent_id' => $parent]);
            $r->assertStatus(422);
            $this->assertStringContainsString('sama sudah ada', (string) $r->json('errors.name.0'), $variant);
        }
        $this->assertSame(2, ProductCategory::count());
    }

    public function test_unicode_normal_forms_are_equivalent(): void
    {
        $agen = $this->agen();
        $this->create($agen, ['name' => "Caf\u{00E9}"])->assertCreated();                // NFC é
        $this->create($agen, ['name' => "Cafe\u{0301}"])->assertStatus(422);              // NFD e + combining acute
        $this->assertSame("Caf\u{00E9}", ProductCategory::first()->name);
    }

    public function test_root_categories_are_deduplicated_and_same_name_is_allowed_under_a_different_parent(): void
    {
        $agen = $this->agen();
        $this->create($agen, ['name' => 'Lainnya'])->assertCreated();
        $this->create($agen, ['name' => 'lainnya'])->assertStatus(422);                    // root duplicate (parent NULL)
        $p1 = $this->create($agen, ['name' => 'Kue'])->assertCreated()->json('data.id');
        $p2 = $this->create($agen, ['name' => 'Roti'])->assertCreated()->json('data.id');
        $this->create($agen, ['name' => 'Spesial', 'parent_id' => $p1])->assertCreated();
        $this->create($agen, ['name' => 'Spesial', 'parent_id' => $p2])->assertCreated();   // different parent: allowed
        $this->create($agen, ['name' => 'Spesial'])->assertCreated();                        // root, not a duplicate of the children
        $this->create($agen, ['name' => 'Spesial', 'parent_id' => $p1])->assertStatus(422);
        $this->assertSame(3, ProductCategory::where('name', 'Spesial')->count());
    }

    public function test_existing_rows_are_never_merged_or_deleted_and_legacy_unnormalised_names_still_block_duplicates(): void
    {
        $agen = $this->agen();
        // legacy un-normalised data written before normalisation existed. (A legacy DUPLICATE pair can no longer exist once the
        // REM-06 unique key is in place; that case is covered by CategoryUniqueKeyMigrationTest's fail-closed scenarios.)
        ProductCategory::create(['name' => 'Dobel', 'slug' => 'dobel-1']);
        ProductCategory::create(['name' => '  Roti   Bakar ', 'slug' => 'roti-bakar']);
        $this->create($agen, ['name' => 'Roti Bakar'])->assertStatus(422);
        $this->create($agen, ['name' => 'dobel'])->assertStatus(422);
        $this->assertSame(2, ProductCategory::count(), 'nothing merged, nothing deleted, nothing added');
    }

    public function test_normalisation_is_pure_and_stored_name_is_the_normalised_form(): void
    {
        $this->assertSame('Kopi Susu', CategoryHierarchyService::normalizeName("  Kopi \u{200B}\t Susu  "));
        $this->assertSame(CategoryHierarchyService::nameKey('KOPI  susu'), CategoryHierarchyService::nameKey("kopi\u{00A0}Susu"));
        $agen = $this->agen();
        $r = $this->create($agen, ['name' => "   Es   Teh\u{200B}   Manis "])->assertCreated()->json('data');
        $this->assertSame('Es Teh Manis', $r['name']);
    }

    // ------------------------------------------------------------------ audit ---

    public function test_audit_row_is_server_controlled_and_minimal(): void
    {
        $agen = $this->agen();
        $admin = $this->admin($agen);
        $root = $this->create($agen, ['name' => 'Root'])->assertCreated()->json('data.id');
        $child = $this->create($admin, ['name' => 'Child', 'parent_id' => $root])->assertCreated()->json('data.id');

        $rows = ActivityLog::where('event', 'category.create')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(['category_id' => $root, 'parent_id' => null, 'actor_role' => 'agen', 'agent_id' => $agen->id, 'result' => 'success'], $rows[0]->properties);
        $this->assertEquals(['category_id' => $child, 'parent_id' => $root, 'actor_role' => 'admin', 'agent_id' => $agen->id, 'result' => 'success'], $rows[1]->properties);
        $this->assertSame($admin->id, (int) $rows[1]->causer_id);
        $this->assertSame(ProductCategory::class, $rows[1]->subject_type);
        $this->assertNotNull($rows[1]->created_at);
        // a rejected duplicate writes no "success" audit row
        $this->create($agen, ['name' => 'Root'])->assertStatus(422);
        $this->assertSame(2, ActivityLog::where('event', 'category.create')->count());
    }

    public function test_advisory_lock_is_released_after_success_and_after_a_rejected_create(): void
    {
        $agen = $this->agen();
        $this->create($agen, ['name' => 'A'])->assertCreated();
        $this->create($agen, ['name' => 'A'])->assertStatus(422);
        $free = (int) DB::selectOne("SELECT IS_FREE_LOCK('primeclassy:product_categories:create') AS f")->f;
        $this->assertSame(1, $free, 'the serialising lock must never leak');
    }
}
