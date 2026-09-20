<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L-008 / Phase K — the legacy→Transit backfill must be resumable and
 * idempotent: retrying a run (or starting a second run) may never add the same
 * legacy quantity to Transit twice. Durable warehouse_migration_markers, not
 * in-memory state, are what guarantee that.
 */
class WarehouseLegacyMigrationRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_retrying_a_completed_run_is_a_no_op(): void
    {
        [$agentId, $productId] = $this->legacyRow(60);

        $this->assertSame(0, Artisan::call('warehouse:migrate-legacy-stock', ['--run-id' => 'retry-run-1']));
        $this->assertSame(60, $this->transit($agentId, $productId));

        // Same run id again: the run is already completed, so nothing is written.
        // Counts are scoped to this fixture (plus its own run id) so the test is
        // independent of whatever other tests left in the shared test database.
        $this->assertSame(0, Artisan::call('warehouse:migrate-legacy-stock', ['--run-id' => 'retry-run-1']));
        $this->assertSame(60, $this->transit($agentId, $productId));
        $this->assertSame(1, $this->markers($agentId, $productId));
        $this->assertSame(1, $this->backfillMovements($agentId));
        $this->assertSame(1, DB::table('warehouse_migration_runs')->whereIn('run_id', ['retry-run-1'])->count());
    }

    public function test_a_brand_new_run_skips_already_migrated_sources_and_never_double_counts(): void
    {
        [$agentId, $productId] = $this->legacyRow(25);

        $this->assertSame(0, Artisan::call('warehouse:migrate-legacy-stock', ['--run-id' => 'retry-run-a']));
        $this->assertSame(25, $this->transit($agentId, $productId));

        $this->assertSame(0, Artisan::call('warehouse:migrate-legacy-stock', ['--run-id' => 'retry-run-b']));

        // A second run must not create a second Transit quantity for the same
        // legacy source row: the durable marker short-circuits it.
        $this->assertSame(25, $this->transit($agentId, $productId));
        $this->assertSame(1, DB::table('warehouse_stocks')->where('agent_id', $agentId)->where('stock_type', 'transit')->count());
        $this->assertSame(1, $this->backfillMovements($agentId));
        $this->assertSame(2, DB::table('warehouse_migration_runs')->whereIn('run_id', ['retry-run-a', 'retry-run-b'])->count());
        $this->assertSame(1, $this->markers($agentId, $productId));
    }

    public function test_a_dry_run_never_writes_quantity_or_markers(): void
    {
        [$agentId, $productId] = $this->legacyRow(15);

        $this->assertSame(0, Artisan::call('warehouse:migrate-legacy-stock', ['--dry-run' => true, '--run-id' => 'retry-dry']));

        $this->assertSame(0, (int) DB::table('warehouse_stocks')->where('agent_id', $agentId)->sum('quantity'));
        $this->assertSame(0, DB::table('warehouse_migration_runs')->whereIn('run_id', ['retry-dry'])->count());
        $this->assertSame(0, DB::table('warehouse_migration_markers')->where('agent_id', $agentId)->count());
        $this->assertSame(0, $this->backfillMovements($agentId));
        $this->assertSame(15, (int) DB::table('product_stocks')->where('product_id', $productId)->value('quantity_on_hand'));
        $this->assertSame($agentId, (int) DB::table('product_stocks')->where('product_id', $productId)->value('agent_id'));
    }

    /** @return array{0: int, 1: int} [agentId, productId] */
    private function legacyRow(int $onHand): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        $product = Product::create([
            'sku' => 'LEGACY-'.Str::uuid(), 'name' => 'Legacy Cake',
            'slug' => 'legacy-'.Str::uuid(), 'has_variations' => false, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'quantity_on_hand' => $onHand, 'quantity_reserved' => 0,
        ]);

        return [$agent->id, $product->id];
    }

    private function transit(int $agentId, int $productId): int
    {
        return (int) (WarehouseStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)->where('product_id', $productId)
            ->where('stock_type', 'transit')->value('quantity') ?? 0);
    }

    private function markers(int $agentId, int $productId): int
    {
        return DB::table('warehouse_migration_markers')
            ->where('agent_id', $agentId)->where('product_id', $productId)
            ->where('status', 'migrated')->count();
    }

    private function backfillMovements(int $agentId): int
    {
        return DB::table('stock_movements')
            ->where('agent_id', $agentId)->where('type', 'legacy_backfill')->count();
    }
}