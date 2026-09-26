<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Support\PermissionMap;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L-003 — after the warehouse-authoritative cutover Agent/Admin keep catalog
 * metadata rights but must never be able to move physical stock through the
 * legacy POST /stock/adjust path. The gate is server-side (AdjustStockRequest +
 * StockService), reusing the Phase H cutover switch config('warehouse.authoritative')
 * — never a second flag — while the warehouse workflows stay authoritative.
 */
class LegacyStockAdjustmentLockdownTest extends TestCase
{
    use RefreshDatabase;

    private mixed $originalAuthoritative = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->originalAuthoritative = Config::get('warehouse.authoritative');
    }

    protected function tearDown(): void
    {
        Config::set('warehouse.authoritative', $this->originalAuthoritative);
        parent::tearDown();
    }

    public function test_authoritative_mode_is_the_environment_independent_default(): void
    {
        $environment = Env::getRepository();
        $original = $environment->get('WAREHOUSE_STOCK_AUTHORITATIVE');

        try {
            $environment->clear('WAREHOUSE_STOCK_AUTHORITATIVE');

            $configuration = require config_path('warehouse.php');

            $this->assertTrue($configuration['authoritative']);
        } finally {
            if ($original !== null) {
                $environment->set('WAREHOUSE_STOCK_AUTHORITATIVE', $original);
            }
        }
    }

    public function test_agent_and_admin_cannot_adjust_stock_in_warehouse_authoritative_mode(): void
    {
        Config::set('warehouse.authoritative', true);
        $branch = $this->branch();
        $product = $this->product();

        foreach (['agent', 'admin'] as $role) {
            $this->actingAs($branch[$role])
                ->postJson('/api/v1/stock/adjust', ['product_id' => $product->id, 'delta' => 25, 'reason' => 'legacy'])
                ->assertForbidden();
        }

        // A forged request that bypasses the SPA entirely is refused the same way:
        // no stock row is created or changed by either actor. Assertions are
        // scoped to this fixture so the test stays order-independent.
        $this->assertSame(0, DB::table('product_stocks')->where('agent_id', $branch['agent']->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('agent_id', $branch['agent']->id)->count());
    }

    public function test_variation_legacy_adjustment_is_also_denied_in_warehouse_authoritative_mode(): void
    {
        Config::set('warehouse.authoritative', true);
        $branch = $this->branch();
        $product = $this->product(withVariation: true);
        $variation = $product->variations()->firstOrFail();

        $this->actingAs($branch['agent'])
            ->postJson('/api/v1/stock/adjust', ['product_variation_id' => $variation->id, 'delta' => 5, 'reason' => 'legacy'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('product_variation_stocks')->where('agent_id', $branch['agent']->id)->count());
    }

    public function test_super_admin_can_never_use_the_legacy_adjustment_endpoint(): void
    {
        $branch = $this->branch();
        $product = $this->product();

        $this->actingAs($branch['super_admin'])
            ->postJson('/api/v1/stock/adjust', ['product_id' => $product->id, 'delta' => 1, 'reason' => 'bypass attempt'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('product_stocks')->where('agent_id', $branch['agent']->id)->count());
    }

    public function test_warehouse_workflow_still_moves_stock_in_warehouse_authoritative_mode(): void
    {
        Config::set('warehouse.authoritative', true);
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 40]);

        $sub = \App\Models\WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'LOCK-SUB', 'name' => 'Sub', 'created_by' => $branch['agent']->id]);

        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $sub->id, 'reference' => 'LOCK-1',
            'items' => [['product_id' => $product->id, 'quantity' => 15]],
        ])->assertCreated()->json('data');

        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();

        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 25]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'quantity' => 15]);
    }

    public function test_compatibility_mode_preserves_the_legacy_adjustment_behaviour(): void
    {
        Config::set('warehouse.authoritative', false);
        $branch = $this->branch();
        $product = $this->product();

        $this->actingAs($branch['agent'])
            ->postJson('/api/v1/stock/adjust', ['product_id' => $product->id, 'delta' => 7, 'reason' => 'compat mode restock'])
            ->assertOk();

        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjustment', 'quantity' => 7]);
    }

    public function test_capability_hint_only_offers_legacy_adjustment_while_compatibility_mode_is_active(): void
    {
        Config::set('warehouse.authoritative', false);
        $this->assertContains('stock.adjust.legacy', PermissionMap::forRole('agen'));
        $this->assertContains('stock.adjust.legacy', PermissionMap::forRole('admin'));

        Config::set('warehouse.authoritative', true);
        $this->assertNotContains('stock.adjust.legacy', PermissionMap::forRole('agen'));
        $this->assertNotContains('stock.adjust.legacy', PermissionMap::forRole('admin'));
        // Viewing stock and the warehouse workflows stay available.
        $this->assertContains('stock.view.own', PermissionMap::forRole('agen'));
        $this->assertContains('stock.view.own', PermissionMap::forRole('admin'));
    }

    /** @return array{agent: User, admin: User, gudang: User, super_admin: User} */
    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);

        return [
            'super_admin' => User::factory()->superAdmin()->create(),
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
        ];
    }

    private function product(bool $withVariation = false): Product
    {
        $product = Product::create([
            'name' => 'Lockdown Cake',
            'slug' => 'lockdown-'.Str::uuid(),
            'has_variations' => $withVariation,
            'sku' => $withVariation ? null : 'LOCK-'.Str::uuid(),
            'status' => 'active',
        ]);

        if ($withVariation) {
            $product->variations()->create(['sku' => 'LOCK-V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        }

        return $product->fresh();
    }
}
