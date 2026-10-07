<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / decision D: delivery grouping is DERIVED (Order + requested_delivery_date). There is no
 * invoice/delivery-group table and no per-group payment truth — the Order-level payment summary is
 * the single financial truth.
 */
class DeliveryDateGroupingTest extends TestCase
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

        $productA = Product::create(['sku' => 'GA-'.Str::uuid(), 'name' => 'A', 'slug' => 'ga-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 300, 'status' => 'active']);
        $productB = Product::create(['sku' => 'GB-'.Str::uuid(), 'name' => 'B', 'slug' => 'gb-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 300, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $this->b = compact('agen', 'admin', 'konsumen', 'productA', 'productB');
    }

    private function placeOrder(): Order
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'delivery_date' => '2026-11-01',
                'items' => [
                    ['product_id' => $this->b['productA']->id, 'quantity' => 1],
                    ['product_id' => $this->b['productB']->id, 'quantity' => 1],
                ],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Puts this order's shipments in the KURIR ONLINE canonical state
     * (`shipping_provider_code = 'openroute'`). Changing a requested delivery date is LOCKED to
     * Kurir Online (Human 2026-10-07); these fixtures place ordinary Agent orders but seed no
     * shipping provider, so the quote resolves to the neutral 'free' fallback. Written on the
     * canonical field directly because the rule under test reads exactly this field.
     */
    private function markCourierOnline(int $orderId): void
    {
        \App\Models\Shipment::query()->where('order_id', $orderId)->update(['shipping_provider_code' => 'openroute']);
    }

    public function test_items_sharing_order_and_delivery_date_form_one_group(): void
    {
        $order = $this->placeOrder();

        $response = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk();

        $groups = $response->json('data.delivery_groups');
        $this->assertCount(1, $groups);
        $this->assertSame('2026-11-01', $groups[0]['delivery_date']);
        $this->assertCount(2, $groups[0]['item_ids']);
    }

    public function test_adjusting_an_items_delivery_date_splits_the_group_and_keeps_order_payment_truth(): void
    {
        $order = $this->placeOrder();
        $this->markCourierOnline($order->id);
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $this->b['productA']->id)->firstOrFail();
        $grandTotalBefore = (float) $order->total_amount;

        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", [
                'requested_delivery_date' => '2026-12-15', 'reason' => 'stok belum siap',
            ])->assertOk();

        $response = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $groups = collect($response->json('data.delivery_groups'))->keyBy('delivery_date');

        $this->assertCount(2, $groups);
        $this->assertTrue($groups->has('2026-12-15'));
        $this->assertTrue($groups->has('2026-11-01'));
        $this->assertCount(1, $groups['2026-12-15']['item_ids']);
        $this->assertCount(1, $groups['2026-11-01']['item_ids']);

        // Payment truth stays Order-level and is never duplicated per delivery group.
        $this->assertSame($grandTotalBefore, (float) $response->json('data.payment_summary.grand_total'));
        $this->assertSame($grandTotalBefore, (float) Order::withoutGlobalScopes()->findOrFail($order->id)->total_amount);
        $this->assertDatabaseCount('order_item_adjustments', 0);
    }
}
