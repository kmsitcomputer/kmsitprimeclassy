<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / MAJOR-5: the same counter reconciliation applies to the Agent-sourced path — an increase
 * restores previously cancelled units before counting anything as additional, and a fully
 * cancelled (terminal) line cannot be silently resurrected.
 */
class FulfillmentCounterReconciliationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'CNT-'.Str::uuid(), 'name' => 'Counter Cake', 'slug' => 'counter-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $this->b = compact('agen', 'admin', 'konsumen', 'product');
    }

    private function placeAgentItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    private function reserved(): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved');
    }

    public function test_agent_increase_restores_cancelled_units_before_counting_additional(): void
    {
        $item = $this->placeAgentItem(3);
        $this->assertSame(3, $this->reserved());

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['admin'], 'kurang');
        $item->refresh();
        $this->assertSame(2, $item->fulfilled_quantity);
        $this->assertSame(1, $item->cancelled_quantity);
        $this->assertSame(2, $this->reserved());

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'restore');
        $item->refresh();
        $this->assertSame(3, $item->fulfilled_quantity);
        $this->assertSame(0, $item->cancelled_quantity);
        $this->assertSame(0, $item->additional_quantity);
        $this->assertSame(3, $this->reserved());

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['admin'], 'extra');
        $item->refresh();
        $this->assertSame(4, $item->fulfilled_quantity);
        $this->assertSame(0, $item->cancelled_quantity);
        $this->assertSame(1, $item->additional_quantity);
        $this->assertSame(4, $this->reserved());
    }

    public function test_agent_increase_on_a_fully_cancelled_item_is_rejected(): void
    {
        $item = $this->placeAgentItem(3);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 0, $this->b['admin'], 'cancel all');
        $item->refresh();
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame('dibatalkan', $item->status);

        try {
            app(OrderFulfillmentService::class)->adjustItemQuantity($item, 1, $this->b['admin'], 'reopen');
            $this->fail('Expected the increase on a fully cancelled Agent item to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $item->refresh();
        $this->assertSame('dibatalkan', $item->status);
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(0, $this->reserved());
    }
}
