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
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Maintenance\DevTransactionResetService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DEV MAINTENANCE TOOL tests (primeclassy:reset-dev-transactions). */
class ResetDevTransactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_refuses_wrong_database_even_with_correct_confirmation(): void
    {
        $this->assertNotSame('primeclassy_dev', DB::connection()->getDatabaseName());
        $order = $this->makeTransaction()['order'];

        $this->artisan('primeclassy:reset-dev-transactions', ['--confirm' => 'RESET DEV TRANSACTIONS'])->assertFailed();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_refuses_production_environment(): void
    {
        $order = $this->makeTransaction()['order'];
        $this->app['env'] = 'production';

        $this->artisan('primeclassy:reset-dev-transactions', ['--confirm' => 'RESET DEV TRANSACTIONS'])->assertFailed();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_has_no_force_or_override_option(): void
    {
        $options = array_keys(\Artisan::all()['primeclassy:reset-dev-transactions']->getDefinition()->getOptions());
        $this->assertNotContains('force', $options);
        $this->assertSame([], array_values(array_intersect($options, ['database', 'env', 'allow-production', 'yes'])));
    }

    public function test_reset_deletes_transactions_and_preserves_masters_and_physical_stock(): void
    {
        ['order' => $order, 'agen' => $agen, 'product' => $product, 'location' => $location] = $this->makeTransaction();
        $this->assertGreaterThan(0, StockMovement::count());
        $ownership = WarehouseSubLocation::findOrFail($location->id)->owner_user_id;

        $report = app(DevTransactionResetService::class)->reset();

        foreach ($report['counts_after'] as $table => $count) {
            $this->assertSame(0, $count, $table);
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, StockRequest::withoutGlobalScopes()->count());

        // masters preserved
        $this->assertSame($report['before']['masters'], $report['after']['masters']);
        $this->assertSame($agen->id, AgentProfile::firstOrFail()->user_id);
        $this->assertNotNull(Product::find($product->id));
        $this->assertSame($ownership, WarehouseSubLocation::findOrFail($location->id)->owner_user_id);
        $this->assertSame($report['before']['sub_location_ownership'], $report['after']['sub_location_ownership']);

        // physical stock preserved, reserved cleared
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(50, (int) $stock->quantity_on_hand);
        $this->assertSame(0, (int) $stock->quantity_reserved);
        $this->assertSame(7, (int) WarehouseStock::withoutGlobalScopes()->sum('quantity'));
        $this->assertSame($report['before']['product_stocks']['on_hand'], $report['after']['product_stocks']['on_hand']);
        $this->assertSame($report['before']['warehouse_stocks']['quantity_checksum'], $report['after']['warehouse_stocks']['quantity_checksum']);
        $this->assertSame(0, $report['after']['product_stocks']['reserved']);
    }

    /** @return array{order: Order, agen: User, product: Product, location: WarehouseSubLocation} */
    private function makeTransaction(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Reset', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $sub = User::factory()->create(['agent_id' => $agen->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'owner_user_id' => $sub->id, 'code' => 'RST', 'name' => 'Reset Loc', 'is_active' => true, 'created_by' => $agen->id]);
        $product = Product::create(['sku' => 'RST-'.uniqid(), 'name' => 'Reset Cake', 'slug' => 'reset-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 3]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 7]);
        $order = Order::create(['order_no' => 'RST-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 3000, 'total_amount' => 3000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Reset Cake', 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 3000, 'original_quantity' => 3, 'fulfilled_quantity' => 3, 'status' => 'diproses']);
        $split = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Reset Cake', 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000, 'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diproses', 'split_from_order_item_id' => $item->id]);
        $request = StockRequest::create(['agent_id' => $agen->id, 'order_id' => $order->id, 'request_number' => 'SR-'.uniqid(), 'status' => 'pending']);
        $request->items()->create(['order_item_id' => $split->id, 'product_id' => $product->id, 'sku_snapshot' => 'x', 'requested_qty' => 1, 'fulfilled_qty' => 0, 'remaining_qty' => 1]);
        StockMovement::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'type' => 'reserve', 'quantity' => 3, 'reference_type' => Order::class, 'reference_id' => $order->id, 'created_by' => $agen->id]);

        return compact('order', 'agen', 'product', 'location');
    }
}
