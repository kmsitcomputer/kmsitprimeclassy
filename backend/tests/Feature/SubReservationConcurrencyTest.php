<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * R-03 / decision E: concurrent pre-shipment Sub reservation increases on separate MySQL
 * connections must serialize on the Sub stock row — free sellable capacity is never oversold and
 * physical Sub stock never moves.
 */
class SubReservationConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_concurrent_sub_reservation_increases_never_oversell_sellable(): void
    {
        $fixture = $this->createFixture();
        $iterations = 5;

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $harness = new ConcurrencyHarness;

            $report = $harness->runServiceRace(
                ['op' => 'sub-increase', 'actor_id' => $fixture['sub']->id, 'subject_id' => $fixture['item']->id, 'extra' => ['quantity' => 5]],
                ['op' => 'sub-increase', 'actor_id' => $fixture['sub']->id, 'subject_id' => $fixture['item']->id, 'extra' => ['quantity' => 5]],
            );

            $reserved = (int) SubStockReservation::query()->where('order_item_id', $fixture['item']->id)->value('quantity');
            $outcomes = [(string) ($report['a']['outcome'] ?? ''), (string) ($report['b']['outcome'] ?? '')];
            $successes = count(array_filter($outcomes, fn ($o) => $o === 'success'));

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            // Capacity = physical 10 - reserved 3 = 7; two +5 increases can only ever apply once.
            $this->assertSame(8, $reserved, 'the second +5 increase must not oversell free Sub capacity');
            $this->assertSame(1, $successes);
            $this->assertSame(10, $this->physical($fixture));

            fwrite(STDOUT, 'SUB_INCREASE_RACE '.json_encode([
                'iteration' => $iteration,
                'outcomes' => $outcomes,
                'final_reserved' => $reserved,
                'physical' => 10,
            ], JSON_UNESCAPED_SLASHES).PHP_EOL);

            // Reset the reservation so each iteration starts from the same known state.
            SubStockReservation::query()->where('order_item_id', $fixture['item']->id)
                ->update(['quantity' => 3, 'status' => 'active', 'released_at' => null, 'released_by' => null, 'release_reason' => null]);
        }
    }

    private function physical(array $fixture): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()
            ->where('stock_type', 'sub')->where('sub_location_id', $fixture['location']->id)
            ->where('product_id', $fixture['product']->id)->value('quantity');
    }

    private function createFixture(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Concurrency Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = Product::create(['sku' => 'SUBCONC-'.Str::uuid(), 'name' => 'Sub Race Cake', 'slug' => 'sub-race-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'CR1', 'name' => 'CR1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);

        $order = Order::create([
            'order_no' => 'SUBCONC-'.Str::random(10), 'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 3000, 'total_amount' => 3000,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'stock_source' => 'sub', 'sub_location_id' => $location->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 3000,
            'original_quantity' => 3, 'fulfilled_quantity' => 3, 'status' => 'diproses',
        ]);
        SubStockReservation::create([
            'agent_id' => $agen->id, 'sub_location_id' => $location->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'quantity' => 3, 'status' => 'active',
        ]);

        return compact('agen', 'sub', 'konsumen', 'product', 'location', 'order', 'item');
    }
}
