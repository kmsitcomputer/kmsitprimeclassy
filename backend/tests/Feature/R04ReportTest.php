<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Payment\PaymentSummaryService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-04 — operational transaction report (one row per order_item, exact 14-column contract,
 * current/latest actor names incl. self_sub courier) and the per-order finance report
 * (PaymentSummaryService-backed, one row per order).
 */
class R04ReportTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    private const TRANSACTION_COLUMNS = ['order_no', 'order_date', 'sku', 'product', 'unit_price', 'quantity', 'item_status', 'subtotal', 'customer', 'delivery_date', 'courier', 'order_status', 'sales', 'korsal'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sales->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $productA = Product::create(['sku' => 'RA-'.Str::uuid(), 'name' => 'Report A', 'slug' => 'ra-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        $productB = Product::create(['sku' => 'RB-'.Str::uuid(), 'name' => 'Report B', 'slug' => 'rb-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'stock_type' => 'transit', 'quantity' => 30]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'R1', 'name' => 'R1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 20]);

        $this->b = compact('agen', 'admin', 'keuangan', 'gudang', 'kurir', 'sales', 'konsumen', 'sub', 'productA', 'productB', 'location');
    }

    private function placeAgentOrderTwoItems(): Order
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [
                    ['product_id' => $this->b['productA']->id, 'quantity' => 1],
                    ['product_id' => $this->b['productB']->id, 'quantity' => 1],
                ],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function placeSubOrder(): Order
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub',
                'items' => [['product_id' => $this->b['productA']->id, 'quantity' => 1]],
                'recipient_name' => 'Sub Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sub',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_operational_report_is_one_row_per_order_item_with_the_exact_columns(): void
    {
        $order = $this->placeAgentOrderTwoItems();

        $rows = collect($this->actingAs($this->b['admin'])
            ->getJson('/api/v1/reports/transactions?per_page=200')
            ->assertOk()->json('data'))
            ->where('order_id', $order->id)->values();

        $this->assertCount(2, $rows, 'one row per order_item, never grouped or duplicated');
        foreach (self::TRANSACTION_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $rows->first());
        }
    }

    public function test_operational_report_resolves_current_names_for_sales_and_self_sub_courier(): void
    {
        $agentOrder = $this->placeAgentOrderTwoItems();
        $subOrder = $this->placeSubOrder();

        // The transactions report has no free-text search filter — fetch the page and filter by id.
        $rows = collect($this->actingAs($this->b['admin'])->getJson('/api/v1/reports/transactions?per_page=200')->assertOk()->json('data'));
        $agentRow = $rows->firstWhere('order_id', $agentOrder->id);
        $subRow = $rows->firstWhere('order_id', $subOrder->id);

        $this->assertNotNull($agentRow);
        $this->assertNotNull($subRow);
        $this->assertSame($this->b['sales']->name, $agentRow['sales']);

        $subShipment = $subOrder->items()->firstOrFail()->shipment;
        $this->assertSame('self_sub', $subShipment->delivery_mode);
        $this->assertSame($this->b['sub']->id, $subShipment->self_delivered_by_user_id);
        $this->assertSame($this->b['sub']->name, $subRow['courier'], 'self_sub courier resolves to the self-delivering Sales-Kurir-Sub');

        // Current/latest names: renaming the actors is reflected on the next render.
        $this->b['sales']->update(['name' => 'Sales Renamed']);
        $this->b['sub']->update(['name' => 'Sub Renamed']);

        $rows = collect($this->actingAs($this->b['admin'])->getJson('/api/v1/reports/transactions?per_page=200')->assertOk()->json('data'));
        $this->assertSame('Sales Renamed', $rows->firstWhere('order_id', $agentOrder->id)['sales']);
        $this->assertSame('Sub Renamed', $rows->firstWhere('order_id', $subOrder->id)['courier']);
    }

    public function test_finance_report_is_one_row_per_order_using_payment_summary(): void
    {
        $order = $this->placeAgentOrderTwoItems();
        $order->update(['payment_status' => 'paid', 'paid_amount' => $order->total_amount, 'remaining_amount' => 0]);
        $order = $order->fresh();
        $expected = PaymentSummaryService::summarize($order);

        $rows = collect($this->actingAs($this->b['admin'])->getJson('/api/v1/reports/finance-orders?per_page=200')->assertOk()->json('data'))
            ->where('order_id', $order->id)->values();

        $this->assertCount(1, $rows, 'one canonical row per order (2 items must not duplicate it)');
        $row = $rows->first();
        $this->assertSame($order->id, $row['order_id']);
        $this->assertEquals($expected['grand_total'], $row['grand_total']);
        $this->assertEquals($expected['total_paid'], $row['total_paid']);
        $this->assertEquals($expected['remaining_balance'], $row['remaining']);
        $this->assertSame($expected['payment_status'], $row['payment_status']);

        // Finance authority gate.
        $this->actingAs($this->b['keuangan'])->getJson('/api/v1/reports/finance-orders')->assertOk();
        $this->actingAs($this->b['kurir'])->getJson('/api/v1/reports/finance-orders')->assertForbidden();
        $this->actingAs($this->b['gudang'])->getJson('/api/v1/reports/finance-orders')->assertForbidden();
    }
}
