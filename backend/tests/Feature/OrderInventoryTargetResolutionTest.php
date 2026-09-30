<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\OrderService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAJOR A — one effective inventory target per order line.
 *
 * The canonical capacity prelock and the actual reserve/deduct path must consume the SAME resolved
 * target. A simple product is a product target and must not carry a variation id; a variation
 * product is a variation target. Accepting a bogus variation on a simple product previously let the
 * prelock target differ from the reserved target (a deadlock vector), so it is now refused outright.
 */
class OrderInventoryTargetResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const DESTINATION = [
        'recipient_name' => 'Target Resolution Buyer',
        'recipient_phone' => '0811000000',
        'address_line' => 'Target Resolution Address',
        'village_id' => null,
        'latitude' => -6.2,
        'longitude' => 106.8,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Target Branch', 'address' => 'Target Address', 'latitude' => -6.2, 'longitude' => 106.8]);
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);

        $simple = Product::create(['sku' => 'TR-'.Str::uuid(), 'name' => 'Simple', 'slug' => 'tr-simple-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $simple->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $simple->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $variationProduct = Product::create(['sku' => null, 'name' => 'Variation', 'slug' => 'tr-variation-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $variationProduct->id, 'sku' => 'TR-V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $otherProduct = Product::create(['sku' => null, 'name' => 'Other Variation', 'slug' => 'tr-other-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $otherVariation = ProductVariation::create(['product_id' => $otherProduct->id, 'sku' => 'TR-O-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);

        return compact('agent', 'buyer', 'simple', 'variationProduct', 'variation', 'otherProduct', 'otherVariation');
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function order(array $f, array $lines): Order
    {
        return app(OrderService::class)->createOrder($f['buyer'], $lines, self::DESTINATION, $f['buyer'], 'cod', null, 'tr-'.Str::uuid(), null, null, null, null, null);
    }

    public function test_simple_product_with_a_supplied_variation_id_is_rejected_without_an_order_or_reservation(): void
    {
        $f = $this->fixture();

        try {
            $this->order($f, [['product_id' => $f['simple']->id, 'product_variation_id' => $f['variation']->id, 'quantity' => 1]]);
            $this->fail('a variation id on a simple product must be rejected');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $f['simple']->id)->value('quantity_reserved'));
        $this->assertSame(0, (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_variation_id', $f['variation']->id)->value('quantity_reserved'));
    }

    public function test_variation_product_without_a_variation_is_rejected(): void
    {
        $f = $this->fixture();

        try {
            $this->order($f, [['product_id' => $f['variationProduct']->id, 'quantity' => 1]]);
            $this->fail('a variation product must require a variation');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_variation_from_another_product_is_rejected(): void
    {
        $f = $this->fixture();

        $this->expectException(ModelNotFoundException::class);

        $this->order($f, [['product_id' => $f['variationProduct']->id, 'product_variation_id' => $f['otherVariation']->id, 'quantity' => 1]]);
    }

    public function test_valid_simple_and_valid_variation_lines_reserve_their_own_effective_target(): void
    {
        $f = $this->fixture();

        $order = $this->order($f, [
            ['product_id' => $f['simple']->id, 'quantity' => 3],
            ['product_id' => $f['variationProduct']->id, 'product_variation_id' => $f['variation']->id, 'quantity' => 2],
        ]);

        $this->assertSame(2, $order->items()->count());

        // Effective target of the simple line is the PRODUCT; effective target of the variation line is the VARIATION.
        $this->assertSame(3, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $f['simple']->id)->value('quantity_reserved'));
        $this->assertSame(2, (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_variation_id', $f['variation']->id)->value('quantity_reserved'));

        // The variation line never touches the parent product's Agent stock, and vice versa.
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $f['variationProduct']->id)->value('quantity_reserved'));
        $this->assertSame(0, (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_variation_id', $f['otherVariation']->id)->value('quantity_reserved'));
    }

    public function test_prelock_and_reserve_use_the_same_effective_target_for_mixed_lines(): void
    {
        $f = $this->fixture();

        // Same order the canonical prelock sorts to (product before variation); the reservation rows
        // that end up held must be exactly those two targets and nothing else.
        $order = $this->order($f, [
            ['product_id' => $f['simple']->id, 'quantity' => 4],
            ['product_id' => $f['variationProduct']->id, 'product_variation_id' => $f['variation']->id, 'quantity' => 4],
        ]);

        $reserved = $order->items()->get()->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_variation_id' => $item->product_variation_id,
            'stock_source' => $item->stock_source,
        ])->all();

        $this->assertSame([
            ['product_id' => $f['simple']->id, 'product_variation_id' => null, 'stock_source' => 'agent'],
            ['product_id' => $f['variationProduct']->id, 'product_variation_id' => $f['variation']->id, 'stock_source' => 'agent'],
        ], $reserved);

        $this->assertSame(4, (int) ProductStock::withoutGlobalScopes()->where('product_id', $f['simple']->id)->value('quantity_reserved'));
        $this->assertSame(4, (int) ProductVariationStock::withoutGlobalScopes()->where('product_variation_id', $f['variation']->id)->value('quantity_reserved'));
    }
}
