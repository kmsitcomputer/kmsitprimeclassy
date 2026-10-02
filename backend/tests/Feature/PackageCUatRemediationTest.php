<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Order\ShipmentGroupingService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — Production UAT remediation.
 *  A. shipment / resi = ORDER + REQUESTED DELIVERY DATE (not per product)
 *  B. per-product (proposal-item) Admin approval/rejection
 *  C. Stock Request quantity follows the order
 *  D/E. consumer delivery plan + Gudang Order/Diajukan/Dipenuhi/Sisa
 */
class PackageCUatRemediationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $price = 10000, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'UAT-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $product;
    }

    /** Transit stock so Gudang proposals can actually be approved. */
    private function transit(User $agen, Product $product, int $qty = 50): void
    {
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $qty]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);
    }

    /** @param array<int, array{0:Product,1:int}> $lines */
    private function order(User $konsumen, array $lines, ?string $date = null): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', array_filter([
            'payment_method_code' => 'cod',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            'delivery_date' => $date,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]));
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function item(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    private function requestItem(Order $order, Product $product): StockRequestItem
    {
        return StockRequestItem::query()->whereHas('request', fn ($q) => $q->withoutGlobalScopes()->where('order_id', $order->id))->where('product_id', $product->id)->firstOrFail();
    }

    private function transitQty(User $agen, Product $product): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $product->id)->where('stock_type', 'transit')->sum('quantity');
    }

    private function reserved(User $agen, Product $product): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $product->id)->value('quantity_reserved');
    }

    // ===================== C. legacy demand accounting follows the order =====================

    public function test_quantity_increase_and_decrease_reconcile_the_stock_request(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $this->transit($agen, $a);
        $order = $this->order($konsumen, [[$a, 5]]);
        $item = $this->item($order, $a);
        $adjust = fn (int $qty) => $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => $qty, 'reason' => 'ubah']);

        $adjust(3)->assertOk();
        $ri = $this->requestItem($order, $a);
        $this->assertSame([3, 0, 3], [$ri->requested_qty, $ri->fulfilled_qty, $ri->remaining_qty]);

        $adjust(2)->assertOk();
        $ri->refresh();
        $this->assertSame([2, 0, 2], [$ri->requested_qty, $ri->fulfilled_qty, $ri->remaining_qty]);

        $adjust(4)->assertOk();
        $ri->refresh();
        $this->assertSame([4, 0, 4], [$ri->requested_qty, $ri->fulfilled_qty, $ri->remaining_qty]);
        $this->assertSame('pending', StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->value('status'));

        // Stock Request is internal demand accounting; quantity changes never create warehouse fulfillments.
        $this->assertSame(4, $item->fresh()->fulfilled_quantity);
        $this->assertSame(4, $ri->fresh()->requested_qty);
        $this->assertSame(1, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertSame(1, StockRequestItem::where('order_item_id', $item->id)->count());
    }

    // ===================== A. shipment = ORDER + DATE =====================

    public function test_checkout_groups_items_by_delivery_date_into_one_shipment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C')];
        $order = $this->order($konsumen, [[$a, 2], [$b, 1], [$c, 3]], now()->addDays(3)->toDateString());

        $shipmentIds = OrderItem::where('order_id', $order->id)->pluck('shipment_id')->unique();
        $this->assertCount(1, $shipmentIds, 'same order + same date => ONE shipment/resi');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertNotNull(Shipment::find($shipmentIds->first())->shipping_fee_snapshot);
    }

    public function test_reschedule_regroups_by_date_and_back_without_empty_shells_or_lost_fee(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $order = $this->order($konsumen, [[$a, 2], [$b, 1], [$c, 3]], $d1);
        [$ia, $ib, $ic] = [$this->item($order, $a), $this->item($order, $b), $this->item($order, $c)];
        $move = fn (OrderItem $i, string $d) => $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$i->id}/reschedule", ['requested_delivery_date' => $d, 'reason' => 'ubah']);

        // C -> d2: two shipments (A,B on d1 | C on d2).
        $move($ic, $d2)->assertOk();
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame($ia->fresh()->shipment_id, $ib->fresh()->shipment_id);
        $this->assertNotSame($ia->fresh()->shipment_id, $ic->fresh()->shipment_id);

        // B -> d2: B now travels with C; A alone on d1.
        $move($ib, $d2)->assertOk();
        $this->assertSame($ib->fresh()->shipment_id, $ic->fresh()->shipment_id);
        $this->assertNotSame($ia->fresh()->shipment_id, $ib->fresh()->shipment_id);
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());

        // Everything back to d1: ONE shipment again, no empty shell, shipping fee snapshot preserved.
        $move($ib, $d1)->assertOk();
        $move($ic, $d1)->assertOk();
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertCount(1, OrderItem::where('order_id', $order->id)->pluck('shipment_id')->unique());
        $this->assertNotNull(Shipment::where('order_id', $order->id)->value('shipping_fee_snapshot'));
    }

    public function test_partial_reschedule_split_child_joins_the_group_for_its_date(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $c] = [$this->product($agen, 'A'), $this->product($agen, 'C')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(7)->toDateString();
        $order = $this->order($konsumen, [[$a, 2], [$c, 3]], $d1);
        $ia = $this->item($order, $a);
        $ic = $this->item($order, $c);

        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$ia->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'ubah'])->assertOk();
        // 1 of C's 3 units goes to d2: the split child joins A's existing d2 shipment.
        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$ic->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        $child = OrderItem::where('split_from_order_item_id', $ic->id)->firstOrFail();
        $this->assertSame($ia->fresh()->shipment_id, $child->shipment_id);
        $this->assertNotSame($ic->fresh()->shipment_id, $child->shipment_id);
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(2, $ic->fresh()->fulfilled_quantity);
    }

    public function test_sc03_add_line_follows_the_same_grouping_and_replay_creates_nothing(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $n1, $n2] = [$this->product($agen, 'A'), $this->product($agen, 'N1'), $this->product($agen, 'N2')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1]], $d1);
        $this->transit($agen, $a);
        $this->transit($agen, $n1);
        $this->transit($agen, $n2);
        $add = fn (Product $p, ?string $date, string $key) => $this->actingAs($admin)->withHeaders(['Idempotency-Key' => $key])->postJson("/api/v1/orders/{$order->id}/items", array_filter(['product_id' => $p->id, 'quantity' => 1, 'reason' => 'tambah', 'requested_delivery_date' => $date]));

        // Same date as the existing line => joins its shipment.
        $add($n1, $d1, (string) Str::uuid())->assertCreated();
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        // New date => its own shipment; a second line on that date joins it; replay adds nothing.
        $key = (string) Str::uuid();
        $add($n2, $d2, $key)->assertCreated();
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $add($n2, $d2, $key)->assertOk();
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(3, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame($this->item($order, $a)->shipment_id, $this->item($order, $n1)->shipment_id);
    }

    public function test_only_mutable_shipments_are_regrouped_and_finalized_history_is_preserved(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C')];
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1], [$c, 1]], $d1);
        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
        $fee = Shipment::where('order_id', $order->id)->value('shipping_fee_snapshot');

        // Simulate LEGACY per-item shipments: the first holds the fee, the 3rd is already assigned to a courier.
        $base = Shipment::where('order_id', $order->id)->firstOrFail();
        $s2 = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $s2->save();
        $s3 = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $courier = Courier::create(['type' => 'internal', 'user_id' => User::factory()->kurir()->create(['agent_id' => $agen->id])->id, 'agent_id' => $agen->id, 'name' => 'K', 'is_active' => true]);
        $s3->courier_id = $courier->id;
        $s3->save();
        $items[1]->update(['shipment_id' => $s2->id]);
        $items[2]->update(['shipment_id' => $s3->id]);

        // Dry run changes nothing.
        $this->artisan('shipments:regroup')->assertSuccessful();
        $this->assertSame(3, Shipment::where('order_id', $order->id)->count());

        $this->artisan('shipments:regroup', ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count(), 'the two mutable shipments merged; the courier-assigned one is untouched');
        $this->assertSame($items[0]->fresh()->shipment_id, $items[1]->fresh()->shipment_id);
        $this->assertSame($s3->id, $items[2]->fresh()->shipment_id);
        $this->assertSame($courier->id, $s3->fresh()->courier_id);
        $this->assertEquals($fee, Shipment::find($items[0]->fresh()->shipment_id)->shipping_fee_snapshot);

        // Idempotent.
        $this->artisan('shipments:regroup', ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());

        // A new same-date item never joins the committed (assigned) shipment.
        $d = $this->product($agen, 'D');
        $this->transit($agen, $d);
        $this->actingAs($admin)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order->id}/items", ['product_id' => $d->id, 'quantity' => 1, 'reason' => 'x', 'requested_delivery_date' => $d1])->assertCreated();
        $this->assertNotSame($s3->id, $this->item($order, $d)->shipment_id);
        $this->assertSame($items[0]->fresh()->shipment_id, $this->item($order, $d)->shipment_id);
        $this->assertTrue(ShipmentGroupingService::isMutable(Shipment::find($items[0]->fresh()->shipment_id)));
        $this->assertFalse(ShipmentGroupingService::isMutable($s3->fresh()));
    }

    public function test_one_grouped_shipment_prints_one_resi_with_every_product(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'Produk A'), $this->product($agen, 'Produk B')];
        $order = $this->order($konsumen, [[$a, 2], [$b, 1]], now()->addDays(3)->toDateString());
        $shipmentId = $this->item($order, $a)->shipment_id;

        $receipt = $this->actingAs($admin)->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk()->json('data');
        $this->assertSame(['Produk A', 'Produk B'], collect($receipt['items'])->pluck('product_name')->sort()->values()->all());
        $this->assertSame(3, $receipt['total_item_count']);

        // A cancelled/zero line is not printed on the group's resi.
        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $b)->id}/fulfillment", ['fulfilled_quantity' => 0, 'reason' => 'batal'])->assertOk();
        $receipt = $this->actingAs($admin)->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk()->json('data');
        $this->assertSame(['Produk A'], collect($receipt['items'])->pluck('product_name')->all());
    }

    // ===================== D/E. consumer =====================

    public function test_consumer_sees_delivery_groups_and_an_admin_date_change_without_warehouse_internals(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $order = $this->order($konsumen, [[$a, 2], [$b, 1], [$c, 3]], $d1);
        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $c)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'ubah'])->assertOk();

        $groups = fn () => collect($this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.delivery_groups'));
        $g = $groups();
        $this->assertCount(2, $g);
        $this->assertSame(['A', 'B'], $g->firstWhere('delivery_date', $d1)['items'] ? collect($g->firstWhere('delivery_date', $d1)['items'])->pluck('product_name')->sort()->values()->all() : []);
        $this->assertSame([['C', 3]], collect($g->firstWhere('delivery_date', $d2)['items'])->map(fn ($i) => [$i['product_name'], $i['quantity']])->all());
        $this->assertCount(1, $g->firstWhere('delivery_date', $d1)['shipments']);

        // Admin moves B to d2: a normal refresh shows the new plan.
        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $b)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'ubah'])->assertOk();
        $g = $groups();
        $this->assertSame(['A'], collect($g->firstWhere('delivery_date', $d1)['items'])->pluck('product_name')->all());
        $this->assertSame(['B', 'C'], collect($g->firstWhere('delivery_date', $d2)['items'])->pluck('product_name')->sort()->values()->all());

        // No warehouse / stock-request / reservation internals in the consumer payload.
        $json = json_encode($this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}")->json('data'));
        foreach (['stock_request', 'proposal', 'quantity_reserved', 'transit', 'decision_status'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    // ===================== regression guards =====================

    public function test_grouping_changes_never_touch_payment_truth_or_stock(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 2], [$b, 1]], now()->addDays(3)->toDateString());
        $before = Order::withoutGlobalScopes()->find($order->id)->only(['total_amount', 'paid_amount', 'remaining_amount', 'payment_status']);
        $stockBefore = [$this->reserved($agen, $a), $this->reserved($agen, $b), ProductStock::withoutGlobalScopes()->where('product_id', $a->id)->value('quantity_on_hand')];

        $this->actingAs($agen)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $b)->id}/reschedule", ['requested_delivery_date' => now()->addDays(8)->toDateString(), 'reason' => 'ubah'])->assertOk();

        $this->assertEquals($before, Order::withoutGlobalScopes()->find($order->id)->only(['total_amount', 'paid_amount', 'remaining_amount', 'payment_status']));
        $this->assertSame($stockBefore, [$this->reserved($agen, $a), $this->reserved($agen, $b), ProductStock::withoutGlobalScopes()->where('product_id', $a->id)->value('quantity_on_hand')]);
    }

    public function test_add_line_authority_is_still_exactly_admin_of_the_same_agent(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $n] = [$this->product($agen, 'A'), $this->product($agen, 'N')];
        $order = $this->order($konsumen, [[$a, 1]]);
        $payload = ['product_id' => $n->id, 'quantity' => 1, 'reason' => 'x'];
        $post = fn (User $u) => $this->actingAs($u)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order->id}/items", $payload);

        $post(User::factory()->superAdmin()->create())->assertForbidden();
        $post($agen)->assertForbidden();
        $foreignAgen = User::factory()->agen()->create();
        $foreignAgen->update(['agent_id' => $foreignAgen->id]);
        $this->assertContains($post(User::factory()->admin()->create(['agent_id' => $foreignAgen->id]))->status(), [403, 404]);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }
}
