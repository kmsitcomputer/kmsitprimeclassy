<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\StockMovement;
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
 * R-02 — two real MySQL connections racing on Sub stock (committed fixtures, restored by the trait).
 */
class SubStockConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $subStock, int $items, int $quantity): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $product = Product::create(['sku' => 'RACE-'.Str::uuid(), 'name' => 'Sub Race', 'slug' => 'sub-race-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'R-'.Str::random(6), 'name' => 'Race', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => $subStock]);

        $created = [];
        for ($i = 0; $i < $items; $i++) {
            $order = Order::create([
                'order_no' => 'R-'.strtoupper(Str::random(12)), 'konsumen_id' => $sub->id, 'agent_id' => $agent->id,
                'payment_method_id' => PaymentMethod::query()->where('code', 'cod')->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid',
                'subtotal_amount' => 0, 'shipping_fee_amount' => 0, 'admin_fee_amount' => 0, 'total_amount' => 0,
                'recipient_name_snapshot' => 'R', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'R',
            ]);
            $created[] = OrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id, 'stock_source' => 'sub', 'sub_location_id' => $location->id,
                'product_name_snapshot' => 'Sub Race', 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000 * $quantity,
                'original_quantity' => $quantity, 'fulfilled_quantity' => $quantity, 'status' => 'diproses',
            ]);
        }

        return compact('agent', 'sub', 'product', 'location') + ['items' => $created];
    }

    public function test_concurrent_sub_reservations_cannot_oversell(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(5, 2, 3);
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'sub-reserve', 'actor_id' => $f['sub']->id, 'subject_id' => $f['items'][0]->id],
                ['op' => 'sub-reserve', 'actor_id' => $f['sub']->id, 'subject_id' => $f['items'][1]->id],
            );

            $successes = collect([$report['a'], $report['b']])->where('outcome', 'success')->count();
            $reserved = (int) SubStockReservation::query()->where('sub_location_id', $f['location']->id)->where('status', 'active')->sum('quantity');
            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(1, $successes, 'stock 5, two reservations of 3: exactly one may win. '.json_encode($report));
            $this->assertSame(3, $reserved);
            $this->assertSame(5, (int) WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $f['location']->id)->value('quantity'), 'physical never moves on reserve');
            fwrite(STDOUT, 'SUB_RESERVE_RACE '.json_encode(['iteration' => $iteration, 'successes' => $successes, 'reserved' => $reserved]).PHP_EOL);
        }
    }

    public function test_concurrent_consume_of_the_same_reservation_decrements_physical_exactly_once(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(5, 1, 3);
            SubStockReservation::create(['agent_id' => $f['agent']->id, 'sub_location_id' => $f['location']->id, 'order_item_id' => $f['items'][0]->id, 'product_id' => $f['product']->id, 'quantity' => 3, 'status' => 'active']);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'sub-consume', 'actor_id' => $f['sub']->id, 'subject_id' => $f['items'][0]->id],
                ['op' => 'sub-consume', 'actor_id' => $f['sub']->id, 'subject_id' => $f['items'][0]->id],
            );

            $this->assertTrue($report['true_overlap']);
            $this->assertSame(2, (int) WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $f['location']->id)->value('quantity'));
            $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('stock_type', 'sub')->where('type', 'out')->where('sub_location_id', $f['location']->id)->count());
            $this->assertSame('consumed', SubStockReservation::query()->where('order_item_id', $f['items'][0]->id)->value('status'));
            fwrite(STDOUT, 'SUB_CONSUME_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }
}
