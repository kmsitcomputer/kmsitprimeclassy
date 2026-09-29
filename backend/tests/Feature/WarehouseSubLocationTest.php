<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseSubLocationTest extends TestCase
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

        return compact('agent', 'admin', 'gudang');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'SUB-'.uniqid(), 'name' => 'Sub Cake', 'slug' => 'sub-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
    }

    public function test_agent_admin_and_gudang_manage_same_agent_sub_metadata_and_no_sub_user_is_created(): void
    {
        $branch = $this->branch();
        $other = $this->branch();
        $ownerA = User::factory()->salesKurirSub()->create(['agent_id' => $branch['agent']->id, 'parent_id' => $branch['agent']->id]);
        $ownerB = User::factory()->salesKurirSub()->create(['agent_id' => $branch['agent']->id, 'parent_id' => $branch['agent']->id]);
        $location = $this->actingAs($branch['agent'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $ownerA->id, 'code' => 'SUB-001', 'name' => 'Bandung Timur'])->assertCreated()->json('data');
        $this->assertDatabaseHas('warehouse_sub_locations', ['id' => $location['id'], 'agent_id' => $branch['agent']->id, 'created_by' => $branch['agent']->id]);
        $this->actingAs($branch['admin'])->patchJson("/api/v1/warehouse/sub-locations/{$location['id']}", ['name' => 'Bandung Timur Updated'])->assertOk();
        $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $ownerB->id, 'code' => 'SUB-002', 'name' => 'Cimahi'])->assertForbidden();
        $created = $this->actingAs($branch['admin'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $ownerB->id, 'code' => 'SUB-002', 'name' => 'Cimahi', 'address' => 'Jl. Contoh No. 10', 'contact_number' => '0812000111'])->assertCreated()->json('data');
        $this->assertSame($branch['agent']->id, $created['agent_id']);
        $this->assertSame('Cimahi', $created['name']);
        $this->assertSame('Jl. Contoh No. 10', $created['address']);
        $this->assertSame('0812000111', $created['contact_number']);
        $this->assertDatabaseHas('warehouse_sub_locations', ['id' => $created['id'], 'agent_id' => $branch['agent']->id, 'created_by' => $branch['admin']->id]);
        $this->actingAs($branch['gudang'])->patchJson("/api/v1/warehouse/sub-locations/{$created['id']}", ['name' => 'Cimahi Updated'])->assertOk();
        $this->assertDatabaseHas('warehouse_sub_locations', ['id' => $created['id'], 'name' => 'Cimahi Updated']);
        $this->actingAs($other['gudang'])->getJson("/api/v1/warehouse/sub-locations/{$location['id']}")->assertNotFound();
        $this->actingAs($other['gudang'])->patchJson("/api/v1/warehouse/sub-locations/{$created['id']}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertDatabaseMissing('roles', ['slug' => 'sub']);
        $this->assertDatabaseMissing('users', ['name' => 'Bandung Timur']);
    }

    public function test_transit_to_sub_and_sub_to_transit_preserve_physical_total_and_exclude_sub_from_sellable(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        $location = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-001', 'name' => 'Bandung Timur', 'created_by' => $branch['agent']->id]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 20]);
        ProductStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseSetting::create(['agent_id' => $branch['agent']->id, 'factory_plan_enabled' => false]);

        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 30]]])->assertCreated()->json('data');
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 70]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 50]);
        $this->actingAs($branch['gudang'])->getJson('/api/v1/warehouse/sellable?product_id='.$product->id)->assertJsonPath('data.available', 60);
        $this->assertSame(120, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity'));

        $back = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'sub', 'source_sub_location_id' => $location->id, 'destination_stock_type' => 'transit', 'items' => [['product_id' => $product->id, 'quantity' => 20]]])->assertCreated()->json('data');
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/transfers/{$back['id']}/approve")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 90]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 30]);
        $this->assertSame(120, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity'));
        $this->assertSame(4, StockMovement::query()->count());
    }

    public function test_transit_to_sub_rejects_reservation_deficit_and_disallows_plan_shipping_pairs(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        $location = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-001', 'name' => 'Bandung Timur', 'created_by' => $branch['agent']->id]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        ProductStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 80]);
        foreach ([['factory_plan', 'sub'], ['sub', 'factory_plan'], ['sub', 'shipping'], ['shipping', 'sub']] as [$source, $destination]) {
            $payload = ['source_stock_type' => $source, 'source_sub_location_id' => $source === 'sub' ? $location->id : null, 'destination_stock_type' => $destination, 'destination_sub_location_id' => $destination === 'sub' ? $location->id : null, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
            $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $payload)->assertUnprocessable();
        }
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 30]]])->assertCreated()->json('data');
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sub_to_sub_requires_distinct_active_locations_and_deactivation_requires_empty_stock(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        $a = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-A', 'name' => 'A', 'created_by' => $branch['agent']->id]);
        $b = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-B', 'name' => 'B', 'created_by' => $branch['agent']->id]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $a->id, 'quantity' => 50]);
        $same = ['source_stock_type' => 'sub', 'source_sub_location_id' => $a->id, 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $a->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $same)->assertUnprocessable();
        $move = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', [...$same, 'destination_sub_location_id' => $b->id, 'items' => [['product_id' => $product->id, 'quantity' => 20]]])->assertCreated()->json('data');
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/transfers/{$move['id']}/approve")->assertOk();
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/sub-locations/{$b->id}/deactivate")->assertUnprocessable();
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/sub-locations/{$a->id}/deactivate")->assertUnprocessable();
        $empty = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-C', 'name' => 'C', 'created_by' => $branch['agent']->id]);
        $this->actingAs($branch['admin'])->postJson("/api/v1/warehouse/sub-locations/{$empty->id}/deactivate")->assertOk();
    }
}
