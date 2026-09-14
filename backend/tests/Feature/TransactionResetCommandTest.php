<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_deletes_transactions_restores_reservations_and_preserves_master_data(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $agent = User::factory()->agen()->create();
        $customer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = Product::create([
            'name' => 'Master Product', 'slug' => 'master-product', 'sku' => 'MASTER-001',
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 2]);
        $method = PaymentMethod::create(['code' => 'test', 'name' => 'Test', 'type' => 'manual', 'is_active' => true]);
        $order = Order::create([
            'order_no' => 'RESET-001', 'konsumen_id' => $customer->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 20000, 'total_amount' => 20000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending']);
        OrderItem::create([
            'order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 10000, 'original_quantity' => 2, 'fulfilled_quantity' => 2,
            'subtotal_snapshot' => 20000, 'status' => 'diterima',
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'reserve',
            'quantity' => 2, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $this->artisan('transactions:reset', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('orders', 1);

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();
        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();

        foreach (['orders', 'order_items', 'shipments', 'payment_transactions', 'commissions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('roles', 8);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'MASTER-001']);
        $this->assertDatabaseHas('product_fees', ['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0,
        ]);
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'order')->count());
    }
}
