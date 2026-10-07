<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Stock\SellableStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    private array $fixture = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_two_overlapping_checkouts_cannot_commit_more_than_one_reservation(): void
    {
        $this->createFixture();
        $harness = new ConcurrencyHarness;
        $iterationReports = [];

        try {
            $sellable = app(SellableStockService::class);
            $this->assertSame(1, $sellable->forProduct($this->fixture['agent']->id, $this->fixture['product']->id)['available']);

            for ($iteration = 1; $iteration <= 5; $iteration++) {
                $this->resetIteration();
                $report = $harness->runCheckout([
                    'agent_id' => $this->fixture['agent']->id,
                    'buyer_a_id' => $this->fixture['buyer_a']->id,
                    'buyer_b_id' => $this->fixture['buyer_b']->id,
                    'product_id' => $this->fixture['product']->id,
                    'quantity' => 1,
                    'destination' => [
                        'recipient_name' => 'Concurrent Buyer',
                        'recipient_phone' => '0811000000',
                        'address_line' => 'Concurrency Test Address',
                        'village_id' => null,
                        'latitude' => -6.2,
                        'longitude' => 106.8,
                    ],
                ]);

                $reserved = (int) ProductStock::withoutGlobalScopes()
                    ->where('agent_id', $this->fixture['agent']->id)
                    ->where('product_id', $this->fixture['product']->id)
                    ->value('quantity_reserved');
                $finalSellable = $sellable->forProduct($this->fixture['agent']->id, $this->fixture['product']->id)['available'];
                $committedOrders = DB::table('orders')->whereIn('konsumen_id', [$this->fixture['buyer_a']->id, $this->fixture['buyer_b']->id])->count();
                $successful = collect([$report['actor_a'], $report['actor_b']])->where('outcome', 'success')->count();

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertLessThanOrEqual(1, $successful);
                $this->assertSame($successful, $reserved);
                $this->assertGreaterThanOrEqual(0, $reserved);
                $this->assertGreaterThanOrEqual(0, $finalSellable);
                $this->assertSame($successful, $committedOrders);

                $iterationReports[] = [
                    'iteration' => $iteration,
                    'actor_a_connection' => $report['actor_a']['connection_id'],
                    'actor_b_connection' => $report['actor_b']['connection_id'],
                    'actor_a_outcome' => $report['actor_a']['outcome'],
                    'actor_b_outcome' => $report['actor_b']['outcome'],
                    'successful_committed' => $successful,
                    'reserved' => $reserved,
                    'sellable' => $finalSellable,
                    'committed_orders' => $committedOrders,
                    'oversell' => $successful > 1,
                ];
                fwrite(STDOUT, 'CHECKOUT_RACE '.json_encode(end($iterationReports), JSON_UNESCAPED_SLASHES).PHP_EOL);
            }

            $this->assertCount(5, $iterationReports);
        } finally {
            $this->cleanupFixture();
        }
    }

    public function test_concurrent_checkouts_cannot_both_redeem_the_final_voucher_use(): void
    {
        $this->createFixture();
        $f = $this->fixture;
        WarehouseStock::where('agent_id', $f['agent']->id)->where('product_id', $f['product']->id)->update(['quantity' => 10]);
        $voucher = \App\Models\Voucher::create(['agent_id' => $f['agent']->id, 'code' => 'FINAL-USE', 'name' => 'Last use', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'max_uses' => 1]);
        $extra = ['lines' => [['product_id' => $f['product']->id, 'quantity' => 1]], 'voucher_code' => $voucher->code, 'destination' => ['recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Race', 'village_id' => null, 'latitude' => -6.2, 'longitude' => 106.8]];
        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'checkout-order', 'actor_id' => $f['buyer_a']->id, 'extra' => $extra + ['buyer_id' => $f['buyer_a']->id]],
            ['op' => 'checkout-order', 'actor_id' => $f['buyer_b']->id, 'extra' => $extra + ['buyer_id' => $f['buyer_b']->id]],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), json_encode($report));
        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205], json_encode($report));
        }
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(1, \App\Models\Order::where('voucher_id', $voucher->id)->count());
        $this->assertSame(1, (int) ProductStock::where('agent_id', $f['agent']->id)->sum('quantity_reserved'));
    }

    private function createFixture(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create([
            'user_id' => $agent->id,
            'store_name' => 'Concurrency Test Branch',
            'address' => 'Concurrency Test Address',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $this->fixture = [
            'agent' => $agent,
            'buyer_a' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'buyer_b' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'product' => Product::create([
                'sku' => 'CONCURRENCY-'.Str::uuid(),
                'name' => 'Concurrency Test Cake',
                'slug' => 'concurrency-test-'.Str::uuid(),
                'has_variations' => false,
                'base_price' => 100000,
                'weight_grams' => 1000,
                'status' => 'active',
            ]),
        ];

        ProductStock::create([
            'agent_id' => $agent->id,
            'product_id' => $this->fixture['product']->id,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
        ]);
        WarehouseStock::create([
            'agent_id' => $agent->id,
            'product_id' => $this->fixture['product']->id,
            'stock_type' => 'transit',
            'quantity' => 1,
        ]);
    }

    private function resetIteration(): void
    {
        $buyerIds = [$this->fixture['buyer_a']->id, $this->fixture['buyer_b']->id];
        $orderIds = DB::table('orders')->whereIn('konsumen_id', $buyerIds)->pluck('id')->all();

        if ($orderIds !== []) {
            DB::table('inventory_cancellation_reversals')->whereIn('order_id', $orderIds)->delete();
            DB::table('stock_request_items')->whereIn('stock_request_id', function ($query) use ($orderIds) {
                $query->select('id')->from('stock_requests')->whereIn('order_id', $orderIds);
            })->delete();
            DB::table('stock_requests')->whereIn('order_id', $orderIds)->delete();
            DB::table('commissions')->whereIn('order_id', $orderIds)->delete();
            DB::table('payment_transactions')->whereIn('order_id', $orderIds)->delete();
            DB::table('shipments')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        ProductStock::withoutGlobalScopes()
            ->where('agent_id', $this->fixture['agent']->id)
            ->where('product_id', $this->fixture['product']->id)
            ->update(['quantity_reserved' => 0]);
    }

    private function cleanupFixture(): void
    {
        if ($this->fixture === []) {
            return;
        }

        $this->resetIteration();
        WarehouseStock::withoutGlobalScopes()->where('product_id', $this->fixture['product']->id)->delete();
        ProductStock::withoutGlobalScopes()->where('product_id', $this->fixture['product']->id)->delete();
        AgentProfile::where('user_id', $this->fixture['agent']->id)->delete();
        Product::whereKey($this->fixture['product']->id)->delete();
        User::whereIn('id', [$this->fixture['buyer_a']->id, $this->fixture['buyer_b']->id, $this->fixture['agent']->id])->delete();
    }
}
