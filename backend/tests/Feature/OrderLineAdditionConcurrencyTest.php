<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\StockRequestProposalService;
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

    /**
     * REV-003: SC-03 addition vs Gudang proposal approval over the SAME inventory target used to
     * deadlock (MariaDB 1213): addition held inventory then waited on the Stock Request, approval held
     * the Stock Request then waited on inventory. Both now take Stock Request first.
     */
    public function test_addition_and_warehouse_approval_over_same_target_do_not_deadlock(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'productA' => $productA, 'order' => $order] = $this->fixture();
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $agen->id, 'factory_plan_enabled' => false]);

        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $firstItem = $request->items()->firstOrFail();
        $proposal = app(StockRequestProposalService::class)->propose($gudang, $request, [['item_id' => $firstItem->id, 'quantity' => 1]]);

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $productA->id, 'quantity' => 2], 'key' => (string) Str::uuid()]],
            ['op' => 'proposal-approve', 'actor_id' => $admin->id, 'subject_id' => $proposal->id, 'extra' => []],
        );

        $this->assertTrue($report['true_overlap']);
        foreach (['a', 'b'] as $side) {
            $this->assertNotSame(1213, $report[$side]['error_code'] ?? null, 'no unhandled deadlock');
            $this->assertSame('success', $report[$side]['outcome'], json_encode($report[$side]));
        }

        $request = $request->fresh();
        $added = $request->items()->where('order_item_id', '!=', $firstItem->id)->get();
        $this->assertCount(1, $added, 'new demand is tracked exactly once');
        $this->assertSame([2, 0, 2], [$added->first()->requested_qty, $added->first()->fulfilled_qty, $added->first()->remaining_qty]);
        $this->assertSame([1, 1, 0], [$firstItem->fresh()->requested_qty, $firstItem->fresh()->fulfilled_qty, $firstItem->fresh()->remaining_qty]);
        $this->assertSame('partial', $request->status);
        $this->assertNull($request->fulfilled_at);
        // reserved: 1 (order) + 2 (added) - 1 (approved fulfillment) ; physical moved exactly once.
        $this->assertSame(2, $this->reserved($agen->id, $productA->id));
        $this->assertSame(19, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $productA->id)->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame(1, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $productA->id)->where('stock_type', 'shipping')->sum('quantity'));
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(1, \App\Models\StockRequestFulfillment::withoutGlobalScopes()->where('stock_request_id', $request->id)->count());
    }

    /** Production UAT: two Admin connections each approve a DIFFERENT product line of one proposal. */
    public function test_concurrent_per_product_approvals_each_execute_exactly_once(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'productA' => $productA, 'order' => $order] = $this->fixture();
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $productB = $this->makeProduct($agen, 'Race B2', 25000, 10);
        foreach ([$productA, $productB] as $p) {
            WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 20]);
        }
        WarehouseSetting::create(['agent_id' => $agen->id, 'factory_plan_enabled' => false]);
        // Add B to the order demand through the real SC-03 path (single connection, before the race).
        app(\App\Services\Order\OrderLineAdditionService::class)->addLine(Order::withoutGlobalScopes()->findOrFail($order->id), ['product_id' => $productB->id, 'quantity' => 2], $admin, null, 'setup', 'cod', (string) Str::uuid());

        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $itemA = $request->items()->where('product_id', $productA->id)->firstOrFail();
        $itemB = $request->items()->where('product_id', $productB->id)->firstOrFail();
        $proposal = app(StockRequestProposalService::class)->propose($gudang, $request, [['item_id' => $itemA->id, 'quantity' => 1], ['item_id' => $itemB->id, 'quantity' => 2]]);
        $pa = $proposal->items->firstWhere('stock_request_item_id', $itemA->id);
        $pb = $proposal->items->firstWhere('stock_request_item_id', $itemB->id);

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'proposal-item-approve', 'actor_id' => $admin->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $pa->id]],
            ['op' => 'proposal-item-approve', 'actor_id' => $admin->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $pb->id]],
        );

        $this->assertTrue($report['true_overlap']);
        foreach (['a', 'b'] as $side) {
            $this->assertNotSame(1213, $report[$side]['error_code'] ?? null, 'no deadlock');
            $this->assertSame('success', $report[$side]['outcome'], json_encode($report[$side]));
        }
        $this->assertSame(19, (int) WarehouseStock::withoutGlobalScopes()->where('product_id', $productA->id)->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame(18, (int) WarehouseStock::withoutGlobalScopes()->where('product_id', $productB->id)->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame([1, 0], [$itemA->fresh()->fulfilled_qty, $itemA->fresh()->remaining_qty]);
        $this->assertSame([2, 0], [$itemB->fresh()->fulfilled_qty, $itemB->fresh()->remaining_qty]);
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame('fulfilled', $request->fresh()->status);
        $this->assertSame(2, \App\Models\StockRequestFulfillment::withoutGlobalScopes()->where('stock_request_id', $request->id)->count());
    }

    /** Production UAT: two additions for the SAME new date must share ONE mutable shipment. */
    public function test_concurrent_same_date_additions_share_one_mutable_shipment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'order' => $order] = $this->fixture();
        $productB = $this->makeProduct($agen, 'Race B3', 25000, 10);
        $productC = $this->makeProduct($agen, 'Race C3', 30000, 10);
        $date = now()->addDays(12)->toDateString();
        $before = Shipment::where('order_id', $order->id)->count();

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $productB->id, 'quantity' => 1], 'date' => $date, 'key' => (string) Str::uuid()]],
            ['op' => 'add-line', 'actor_id' => $admin->id, 'subject_id' => $order->id, 'extra' => ['line' => ['product_id' => $productC->id, 'quantity' => 1], 'date' => $date, 'key' => (string) Str::uuid()]],
        );

        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome']);
        $this->assertSame('success', $report['b']['outcome']);
        $this->assertSame($before + 1, Shipment::where('order_id', $order->id)->count(), 'exactly one new shipment for the shared date');
        $shipments = OrderItem::where('order_id', $order->id)->whereIn('product_id', [$productB->id, $productC->id])->pluck('shipment_id')->unique();
        $this->assertCount(1, $shipments);
    }
}
