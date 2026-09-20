<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseStock;
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

        return compact('agent', 'gudang');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'TRF-'.uniqid(), 'name' => 'Transfer Cake', 'slug' => 'transfer-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
    }

    public function test_gudang_completes_physical_transfer_with_paired_movements_and_handover(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 10]);

        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'reference' => 'SHIP-1',
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();

        $this->assertDatabaseHas('warehouse_stocks', ['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 70]);
        $this->assertDatabaseHas('warehouse_stocks', ['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 40]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_out', 'quantity' => -30, 'stock_type' => 'transit']);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_in', 'quantity' => 30, 'stock_type' => 'shipping']);
        $this->assertDatabaseHas('stock_handovers', ['stock_transfer_id' => $transfer['id'], 'agent_id' => $branch['agent']->id, 'status' => 'handed_over']);
    }

    public function test_completion_is_idempotent_and_cancellation_only_applies_to_pending_transfers(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'items' => [['product_id' => $product->id, 'quantity' => 7]]])->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('stock_handovers', 1);
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/cancel")->assertUnprocessable();
    }

    public function test_invalid_physical_pairs_and_insufficient_source_are_rejected(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        foreach ([['factory_plan', 'transit'], ['transit', 'factory_plan'], ['sales', 'transit'], ['transit', 'sales'], ['transit', 'transit']] as [$source, $destination]) {
            $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => $source, 'destination_stock_type' => $destination, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertUnprocessable();
        }
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 2]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'items' => [['product_id' => $product->id, 'quantity' => 3]]])->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertUnprocessable();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 2]);
    }

    public function test_foreign_agent_and_non_gudang_cannot_create_or_view_transfer(): void
    {
        $branch = $this->branch();
        $foreign = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('data');
        $this->actingAs($foreign['gudang'])->getJson("/api/v1/warehouse/transfers/{$transfer['id']}")->assertNotFound();
        $this->actingAs($branch['agent'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertForbidden();
    }

    public function test_handover_print_is_read_only_and_agent_scoped(): void
    {
        $branch = $this->branch();
        $foreign = $this->branch();
        $product = $this->product();
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $transfer = $this->actingAs($branch['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'shipping', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('data');
        $this->actingAs($branch['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/complete")->assertOk();
        $handoverId = StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->handover->id;
        $before = WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity');
        $this->actingAs($branch['gudang'])->get("/api/v1/warehouse/handovers/{$handoverId}/print")->assertOk()->assertSee($transfer['id'] ? 'Stock Handover' : '');
        $this->actingAs($foreign['gudang'])->get("/api/v1/warehouse/handovers/{$handoverId}/print")->assertNotFound();
        $this->assertSame($before, WarehouseStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->sum('quantity'));
        $this->assertSame(2, StockMovement::query()->count());
    }
}
