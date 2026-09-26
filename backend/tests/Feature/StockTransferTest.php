<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferTest extends TestCase
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
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'TRF-SUB', 'name' => 'Sub', 'created_by' => $agent->id]);

        return compact('agent', 'gudang', 'sub');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'TRF-'.uniqid(), 'name' => 'Transfer Cake', 'slug' => 'transfer-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
    }

    /** Transit -> Sub payload (Shipping is closed to Gudang transfers). */
    private function toSub(array $branch, Product $product, int $quantity, array $extra = []): array
    {
        return $extra + [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $branch['sub']->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];
    }

    private function subQuantity(array $branch, Product $product): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->where('product_id', $product->id)->where('stock_type', 'sub')->where('sub_location_id', $branch['sub']->id)->sum('quantity');
    }

    public function test_gudang_completes_physical_transfer_with_paired_movements_and_handover(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);

        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 30, ['reference' => 'SUB-1']))->assertCreated()->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();

        $this->assertDatabaseHas('warehouse_stocks', ['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 70]);
        $this->assertSame(30, $this->subQuantity($branch, $product));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_out', 'quantity' => -30, 'stock_type' => 'transit']);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_in', 'quantity' => 30, 'stock_type' => 'sub']);
        $this->assertDatabaseHas('stock_handovers', ['stock_transfer_id' => $transfer['id'], 'agent_id' => $branch['agent']->id, 'status' => 'handed_over']);
    }

    public function test_completion_is_idempotent_and_cancellation_only_applies_to_pending_transfers(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 7))->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('stock_handovers', 1);
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/cancel")->assertUnprocessable();
        $this->assertSame('completed', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
    }

    public function test_invalid_physical_pairs_and_insufficient_source_are_rejected(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        foreach ([['factory_plan', 'transit'], ['transit', 'factory_plan'], ['sales', 'transit'], ['transit', 'sales'], ['transit', 'transit']] as [$source, $destination]) {
            $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => $source, 'destination_stock_type' => $destination, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertUnprocessable();
        }
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 2]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 3))->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertUnprocessable();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 2]);
    }

    public function test_gudang_transfers_can_never_touch_shipping_in_either_direction(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 5]);

        foreach ([['transit', 'shipping'], ['shipping', 'transit']] as [$source, $destination]) {
            $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => $source, 'destination_stock_type' => $destination, 'items' => [['product_id' => $product->id, 'quantity' => 4]]])->assertUnprocessable();
        }

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 5]);
    }

    public function test_a_legacy_pending_shipping_transfer_cannot_be_completed(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $legacy = StockTransfer::create(['agent_id' => $branch['agent']->id, 'transfer_number' => 'LEGACY-'.uniqid(), 'source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'status' => 'pending', 'created_by' => $branch['gudang']->id]);
        $legacy->items()->create(['product_id' => $product->id, 'quantity' => 4]);

        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$legacy->id}/complete")->assertUnprocessable();

        $this->assertSame('pending', $legacy->fresh()->status);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_foreign_agent_and_non_gudang_cannot_create_or_view_transfer(): void
    {
        $branch = $this->branch();
        $foreign = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 1))->json('data');
        $this->actingAs($foreign['gudang'])->getJson("/api/v1/warehouse/transfers/{$transfer['id']}")->assertNotFound();
        $this->actingAs($branch['agent'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 1))->assertForbidden();
    }

    public function test_handover_print_is_read_only_and_agent_scoped(): void
    {
        $branch = $this->branch();
        $foreign = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', $this->toSub($branch, $product, 1))->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $handoverId = StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->handover->id;
        $before = WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity');
        $this->actingAs($branch['gudang'])->get("/api/v1/warehouse/handovers/{$handoverId}/print")->assertOk()->assertSee($transfer['id'] ? 'Stock Handover' : '');
        $this->actingAs($foreign['gudang'])->get("/api/v1/warehouse/handovers/{$handoverId}/print")->assertNotFound();
        $this->assertSame($before, WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity'));
        $this->assertSame(2, StockMovement::query()->count());
    }
}
