<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use Database\Seeders\RoleSeeder;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/** REM-06: "A under B" and "B under A" racing on two connections can never form a cycle. */
class CategoryHierarchyConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    public function test_opposite_reparenting_never_creates_a_cycle(): void
    {
        $this->seed(RoleSeeder::class);
        $a = ProductCategory::create(['name' => 'A', 'slug' => 'a-'.uniqid()]);
        $b = ProductCategory::create(['name' => 'B', 'slug' => 'b-'.uniqid()]);
        $sa = \App\Models\User::factory()->superAdmin()->create();

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'category-reparent', 'actor_id' => $sa->id, 'subject_id' => $a->id, 'extra' => ['parent_id' => $b->id]],
            ['op' => 'category-reparent', 'actor_id' => $sa->id, 'subject_id' => $b->id, 'extra' => ['parent_id' => $a->id]],
        );

        $this->assertTrue($report['true_overlap']);
        $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), json_encode($report));
        $pa = $a->fresh()->parent_id;
        $pb = $b->fresh()->parent_id;
        $this->assertFalse($pa === $b->id && $pb === $a->id, 'cycle formed');
        $this->assertTrue(($pa === null) !== ($pb === null), 'exactly one parent assigned');
    }
}
