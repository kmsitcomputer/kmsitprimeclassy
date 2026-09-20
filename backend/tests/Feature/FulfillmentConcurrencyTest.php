<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\SellableStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class FulfillmentConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_overlapping_fulfillments_cannot_duplicate_physical_inventory(): void
    {
        $reports = [];

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $fixture = $this->createFixture();
            $harness = new ConcurrencyHarness;

            try {
                $report = $harness->runFulfillment([
                    'actor_a_id' => $fixture['gudang_a']->id,
                    'actor_b_id' => $fixture['gudang_b']->id,
                    'request_id' => $fixture['request']->id,
                    'item_id' => $fixture['request_item']->id,
                    'quantity' => 6,
                ]);

                $sellable = app(SellableStockService::class);
                $this->assertSame(0, $sellable->forProduct($fixture['agent']->id, $fixture['product']->id)['available']);
                $item = StockRequestItem::findOrFail($fixture['request_item']->id);
                $request = StockRequest::withoutGlobalScopes()->findOrFail($fixture['request']->id);
                $transit = $this->quantity($fixture['agent']->id, $fixture['product']->id, 'transit');
                $shipping = $this->quantity($fixture['agent']->id, $fixture['product']->id, 'shipping');
                $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->where('product_id', $fixture['product']->id)->value('quantity_reserved');
                $movementQuantity = (int) StockMovement::withoutGlobalScopes()
                    ->where('reference_type', StockRequest::class)
                    ->where('reference_id', $fixture['request']->id)
                    ->where('stock_type', 'shipping')
                    ->sum('quantity');
                $successful = collect([$report['actor_a'], $report['actor_b']])->where('outcome', 'success')->count();

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertLessThanOrEqual(1, $successful);
                $this->assertSame(6, (int) $item->fulfilled_qty);
                $this->assertSame(4, (int) $item->remaining_qty);
                $this->assertSame('partial', $request->status);
                $this->assertSame(0, $transit);
                $this->assertSame(6, $shipping);
                $this->assertSame(4, $reserved);
                $this->assertSame(0, $sellable->forProduct($fixture['agent']->id, $fixture['product']->id)['available']);
                $this->assertSame(6, $movementQuantity);
                $this->assertGreaterThanOrEqual(0, $transit);
                $this->assertGreaterThanOrEqual(0, $shipping);
                $this->assertGreaterThanOrEqual(0, $reserved);

                $reports[] = [
                    'iteration' => $iteration,
                    'actor_a_connection' => $report['actor_a']['connection_id'],
                    'actor_b_connection' => $report['actor_b']['connection_id'],
                    'actor_a_outcome' => $report['actor_a']['outcome'],
                    'actor_b_outcome' => $report['actor_b']['outcome'],
                    'initial_transit' => 6,
                    'final_transit' => $transit,
                    'initial_shipping' => 0,
                    'final_shipping' => $shipping,
                    'initial_reserved' => 10,
                    'final_reserved' => $reserved,
                    'final_fulfilled' => $item->fulfilled_qty,
                    'final_remaining' => $item->remaining_qty,
                    'request_status' => $request->status,
                    'movement_quantity' => $movementQuantity,
                    'double_fulfillment' => false,
                ];
                fwrite(STDOUT, 'FULFILLMENT_RACE '.json_encode(end($reports), JSON_UNESCAPED_SLASHES).PHP_EOL);
            } finally {
                $this->cleanupFixture($fixture);
            }
        }

        $this->assertCount(5, $reports);
    }

    private function createFixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $gudangA = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $gudangB = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Fulfillment Race Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $product = Product::create([
            'sku' => 'FULFILLMENT-'.Str::uuid(),
            'name' => 'Fulfillment Race Cake',
            'slug' => 'fulfillment-race-'.Str::uuid(),
            'has_variations' => false,
            'base_price' => 1000,
            'weight_grams' => 100,
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'FUL-'.Str::random(12),
            'konsumen_id' => $konsumen->id,
            'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses',
            'payment_status' => 'unpaid',
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'recipient_name_snapshot' => 'Test',
            'recipient_phone_snapshot' => '0811',
            'address_snapshot' => 'Test',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 1000,
            'subtotal_snapshot' => 10000,
            'original_quantity' => 10,
            'fulfilled_quantity' => 0,
            'status' => 'diproses',
        ]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 6]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 0]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $request = StockRequest::create(['agent_id' => $agent->id, 'order_id' => $order->id, 'request_number' => 'SR-'.Str::uuid(), 'status' => 'pending', 'created_by' => $agent->id]);
        $requestItem = StockRequestItem::create(['stock_request_id' => $request->id, 'order_item_id' => $orderItem->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'requested_qty' => 10, 'fulfilled_qty' => 0, 'remaining_qty' => 10]);

        return [
            'agent' => $agent,
            'gudang_a' => $gudangA,
            'gudang_b' => $gudangB,
            'konsumen' => $konsumen,
            'product' => $product,
            'order' => $order,
            'order_item' => $orderItem,
            'request' => $request,
            'request_item' => $requestItem,
        ];
    }

    private function quantity(int $agentId, int $productId, string $stockType): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->where('stock_type', $stockType)->value('quantity');
    }

    private function cleanupFixture(array $fixture): void
    {
        StockMovement::withoutGlobalScopes()->where('reference_type', StockRequest::class)->where('reference_id', $fixture['request']->id)->delete();
        DB::table('stock_request_fulfillments')->where('stock_request_id', $fixture['request']->id)->delete();
        $fixture['request_item']->delete();
        $fixture['request']->delete();
        $fixture['order_item']->delete();
        $fixture['order']->delete();
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        ProductStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        WarehouseSetting::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        AgentProfile::where('user_id', $fixture['agent']->id)->delete();
        Product::whereKey($fixture['product']->id)->delete();
        User::whereIn('id', [$fixture['gudang_a']->id, $fixture['gudang_b']->id, $fixture['konsumen']->id, $fixture['agent']->id])->delete();
    }
}
