<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/** REM-003 — two real connections cancelling the same undelivered quantity: exactly one wins, stock released once. */
class PartialCancellationConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_concurrent_identical_cancellations_have_one_winner_and_release_stock_once(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'PCC-'.uniqid(), 'name' => 'Race Cake', 'slug' => 'pcc-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'PCC-'.Str::upper(Str::random(10)), 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 4000, 'total_amount' => 4000, 'remaining_amount' => 4000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
        $item = OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 4000, 'original_quantity' => 4, 'fulfilled_quantity' => 4, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 4]);
        \App\Models\WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $side = ['op' => 'cancel-undelivered', 'actor_id' => $admin->id, 'order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 3, 'expected' => 4];
        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace($side, $side);

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), json_encode($report));
        $loser = collect([$report['a'], $report['b']])->firstWhere('outcome', 'failed');
        $this->assertSame(409, $loser['business_status'], json_encode($report));
        $this->assertSame(1, $item->fresh()->fulfilled_quantity);
        $this->assertSame(1, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agent->id)->value('quantity_reserved'));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'release')->count(), 'reservation released exactly once');
    }
}
