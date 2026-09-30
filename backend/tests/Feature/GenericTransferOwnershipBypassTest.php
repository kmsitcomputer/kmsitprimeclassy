<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex Round-2 MAJOR-2 — a generic Gudang transfer created while a Sub Location was still
 * legacy/unowned must be rejected at APPROVAL time if the location became owned in the
 * meantime, not just at creation time. Otherwise Admin/Agen assigning an owner right after
 * Gudang requests a generic transfer lets stock move into an owned Sub without ever going
 * through the Sub request flow (Sales-Kurir-Sub requests, Admin approves, Gudang executes).
 */
class GenericTransferOwnershipBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'admin', 'gudang', 'sub');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'BYPASS-'.uniqid(), 'name' => 'Bypass Cake', 'slug' => 'bypass-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
    }

    public function test_replenish_direction_transfer_is_rejected_at_approval_after_the_location_becomes_owned(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $location = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'BYP-001', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        // Gudang creates the generic transfer while the location is still legacy/unowned.
        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $location->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        // Agen assigns an owner before the transfer is approved.
        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/sub-locations/{$location->id}/assign-owner", ['owner_user_id' => $b['sub']->id])->assertOk();

        // Approval must now reject — the location is owned, so this generic transfer is no
        // longer a legitimate path for it.
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();

        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        $this->assertDatabaseMissing('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id]);
        $this->assertSame('pending', StockTransfer::query()->findOrFail($transfer['id'])->status);
        $this->assertSame(0, StockMovement::query()->where('transfer_id', $transfer['id'])->count());
        $this->assertSame(0, StockHandover::query()->where('stock_transfer_id', $transfer['id'])->count());
    }

    public function test_return_direction_transfer_is_rejected_at_approval_after_the_location_becomes_owned(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $location = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'BYP-002', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 15]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'sub', 'source_sub_location_id' => $location->id, 'destination_stock_type' => 'transit',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/sub-locations/{$location->id}/assign-owner", ['owner_user_id' => $b['sub']->id])->assertOk();

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();

        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 15]);
        $this->assertSame('pending', StockTransfer::query()->findOrFail($transfer['id'])->status);
        $this->assertSame(0, StockMovement::query()->where('transfer_id', $transfer['id'])->count());
        $this->assertSame(0, StockHandover::query()->where('stock_transfer_id', $transfer['id'])->count());
    }

    public function test_generic_transfer_for_a_still_unowned_location_is_unaffected(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $location = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'BYP-003', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $location->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();

        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 40]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);
        $this->assertSame('completed', StockTransfer::query()->findOrFail($transfer['id'])->status);
    }
}
