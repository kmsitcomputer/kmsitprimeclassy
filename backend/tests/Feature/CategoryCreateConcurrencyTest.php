<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/** REM-06: two real MySQL connections racing the same category create converge to exactly ONE row. */
class CategoryCreateConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    private function race(array $a, array $b, User $x, User $y): array
    {
        return (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'category-create', 'actor_id' => $x->id, 'extra' => $a],
            ['op' => 'category-create', 'actor_id' => $y->id, 'extra' => $b],
        );
    }

    /** Exactly one side persisted; the other was refused by the duplicate rule (never a deadlock/SQL error). */
    private function assertOneWinnerOneDuplicateRejection(array $report): void
    {
        $sides = collect([$report['a'], $report['b']]);
        $this->assertSame(1, $sides->where('outcome', 'success')->count(), json_encode($report));
        $loser = $sides->firstWhere('outcome', '!=', 'success');
        $this->assertSame(ValidationException::class, $loser['exception'] ?? null, json_encode($report));
        $this->assertStringContainsString('sama sudah ada', (string) ($loser['message'] ?? ''));
        $this->assertNull($loser['sql_state'] ?? null, 'no database error (deadlock 1213 / duplicate key) may surface');
    }

    private function agents(): array
    {
        $a1 = User::factory()->agen()->create();
        $a1->update(['agent_id' => $a1->id]);
        $a2 = User::factory()->agen()->create();
        $a2->update(['agent_id' => $a2->id]);

        return [$a1, $a2];
    }

    public function test_two_agents_creating_the_same_root_on_an_empty_taxonomy_yield_exactly_one_row(): void
    {
        $this->seed(RoleSeeder::class);
        [$a1, $a2] = $this->agents();
        $this->assertSame(0, ProductCategory::count()); // the empty-table case is where row-lock-only designs deadlock

        $report = $this->race(['name' => 'Minuman'], ['name' => '  minuman  '], $a1, $a2);

        $this->assertTrue($report['true_overlap']);
        $this->assertOneWinnerOneDuplicateRejection($report);
        $this->assertSame(1, ProductCategory::whereNull('parent_id')->count());
    }

    public function test_same_child_name_under_the_same_parent_yields_exactly_one_row(): void
    {
        $this->seed(RoleSeeder::class);
        [$a1, $a2] = $this->agents();
        $parent = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid()]);

        $report = $this->race(['name' => 'Nastar', 'parent_id' => $parent->id], ['name' => 'NASTAR', 'parent_id' => $parent->id], $a1, $a2);

        $this->assertOneWinnerOneDuplicateRejection($report);
        $this->assertSame(1, ProductCategory::where('parent_id', $parent->id)->count());
    }

    public function test_same_name_under_different_parents_both_succeed(): void
    {
        $this->seed(RoleSeeder::class);
        [$a1, $a2] = $this->agents();
        $p1 = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid()]);
        $p2 = ProductCategory::create(['name' => 'Roti', 'slug' => 'roti-'.uniqid()]);

        $report = $this->race(['name' => 'Spesial', 'parent_id' => $p1->id], ['name' => 'Spesial', 'parent_id' => $p2->id], $a1, $a2);

        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('success', $report['b']['outcome'], json_encode($report));
        $this->assertSame(2, ProductCategory::where('name', 'Spesial')->count());
    }
}
