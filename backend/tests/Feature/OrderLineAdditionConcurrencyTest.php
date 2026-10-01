<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Package C / SC-03 concurrency: real two-connection races through the production
 * OrderLineAdditionService path (see .phpunit-concurrency-actor.php `add-line`).
 * Committed fixtures + rebuild between tests via RestoresIsolatedTestDatabase.
 */
class OrderLineAdditionConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, konsumen:User, productA:Product, order:Order} */
    private function fixture(int $productAStock = 10): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Race Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $productA = $this->makeProduct($agen, 'Race A', 10000, $productAStock);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        return compact('agen', 'admin', 'konsumen', 'productA', 'order');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stock): Product
    {
        $product = Product::create([
            'sku' => 'SC03C-'.\Illuminate\Support\Str::uuid(),
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $product;
    }

    private function reserved(int $agentId, int $productId): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_reserved');
    }

    public function test_concurrent_same_key_additions_create_exactly_one_line(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'productA' => $productA, 'order' => $order] = $this->fixture();
        $productB = $this->makeProduct($agen, 'Race B', 25000, 10);
        $key = (string) Str::uuid();
        $side = [
            'op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id,
            'extra' => ['line' => ['product_id' => $productB->id, 'quantity' => 2], 'key' => $key],
        ];

        $report = (new ConcurrencyHarness)->runServiceRace($side, $side);

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertGreaterThanOrEqual(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count());
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->count());
        $this->assertSame(2, $this->reserved($agen->id, $productB->id));
        $this->assertSame(1, Shipment::where('order_id', $order->id)->whereHas('orderItems', fn ($q) => $q->where('idempotency_key', $key))->count());
    }

    public function test_concurrent_distinct_additions_both_succeed(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'order' => $order] = $this->fixture();
        $productB = $this->makeProduct($agen, 'Race B', 25000, 10);
        $productC = $this->makeProduct($agen, 'Race C', 30000, 10);

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $productB->id, 'quantity' => 1], 'key' => (string) Str::uuid()]],
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $productC->id, 'quantity' => 1], 'key' => (string) Str::uuid()]],
        );

        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome']);
        $this->assertSame('success', $report['b']['outcome']);
        $this->assertSame(1, $this->reserved($agen->id, $productB->id));
        $this->assertSame(1, $this->reserved($agen->id, $productC->id));
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->whereIn('product_id', [$productB->id, $productC->id])->count());
    }

    public function test_concurrent_addition_and_reservation_do_not_oversell(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'order' => $order] = $this->fixture();
        $scarce = $this->makeProduct($agen, 'Race Scarce', 30000, 1); // only one unit

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $scarce->id, 'quantity' => 1], 'key' => (string) Str::uuid()]],
            ['op' => 'agent-reserve', 'actor_id' => $admin->id, 'subject_id' => 0, 'extra' => ['agent_id' => $agen->id, 'product_id' => $scarce->id, 'quantity' => 1]],
        );

        $this->assertTrue($report['true_overlap']);
        $successful = collect([$report['a'], $report['b']])->where('outcome', 'success')->count();
        $this->assertSame(1, $successful, 'Exactly one of the concurrent mutations must win the single unit.');
        $this->assertSame(1, $this->reserved($agen->id, $scarce->id));
    }
}
