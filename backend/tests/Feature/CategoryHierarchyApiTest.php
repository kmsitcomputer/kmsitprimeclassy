<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** REM-06: multi-level hierarchy, deterministic read order, and the (unchanged) category write boundary. */
class CategoryHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_three_level_hierarchy_is_created_and_read_back_with_parent_links_in_deterministic_order(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $kue = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Kue'])->assertCreated()->json('data.id');
        $kering = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Kue Kering', 'parent_id' => $kue])->assertCreated()->json('data.id');
        $nastar = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Nastar', 'parent_id' => $kering])->assertCreated()->json('data.id');
        // same sort_order → ordered by name, then id (never by insertion luck)
        $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Brownies', 'parent_id' => $kue, 'sort_order' => 0]);

        $rows = collect($this->getJson('/api/v1/categories')->assertOk()->json('data'));
        $this->assertSame($kering, $rows->firstWhere('id', $nastar)['parent_id']);
        $this->assertSame($kue, $rows->firstWhere('id', $kering)['parent_id']);
        $this->assertNull($rows->firstWhere('id', $kue)['parent_id']);
        $order = $rows->pluck('name')->all();
        $this->assertSame($order, collect($order)->sort()->values()->all(), 'equal sort_order → alphabetical');
        // stable across calls
        $this->assertSame($order, collect($this->getJson('/api/v1/categories')->json('data'))->pluck('name')->all());
    }

    public function test_cycles_self_parenting_and_missing_parents_are_rejected_at_depth(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $a = ProductCategory::create(['name' => 'A', 'slug' => 'a']);
        $b = ProductCategory::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);
        $c = ProductCategory::create(['name' => 'C', 'slug' => 'c', 'parent_id' => $b->id]);

        $this->actingAs($sa)->patchJson("/api/v1/categories/{$a->id}", ['parent_id' => $c->id])->assertStatus(422); // grandchild as parent
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$b->id}", ['parent_id' => $b->id])->assertStatus(422); // self
        $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'X', 'parent_id' => 999999])->assertStatus(422); // missing
        $this->assertNull($a->refresh()->parent_id);
    }
}
