<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * LOCKED BUSINESS RULE (Human 2026-10-07): changing an item's requested delivery date is
 * ONLY legitimate for KURIR ONLINE (canonical `shipments.shipping_provider_code = 'openroute'`).
 *
 *  - Kurir Online  → Admin MAY change it; shipment grouping follows the new date (join an
 *                    existing eligible shipment for that date, otherwise the item's own
 *                    shipment becomes the canonical shipment for the new date).
 *  - Ekspedisi/RajaOngkir → must NOT be re-datable.
 *  - Pickup/Ambil di Tempat → must NOT be re-datable, and must never be turned into a
 *                    courier-online shipment.
 *
 * Enforcement is SERVER-SIDE in OrderFulfillmentService::rescheduleItemDeliveryDate (under the
 * canonical Order lock); frontend hiding is convenience only, so every negative case is proved
 * through the real HTTP endpoint — a direct API call cannot bypass it.
 *
 * The canonical delivery method is read from the persisted `shipping_provider_code`, never
 * inferred from whether a date happens to be set. These tests write that field directly
 * because this file seeds no shipping-provider infrastructure.
 */
class DeliveryDateRescheduleRuleTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    /** KURIR ONLINE (OpenRoute) canonical provider code. */
    private const COURIER_ONLINE = Shipment::PROVIDER_COURIER_ONLINE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Reschedule', 'address' => 'Jl. Reschedule',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $label): Product
    {
        $p = Product::create([
            'sku' => 'RS-'.strtoupper($label).'-'.Str::uuid(), 'name' => 'Produk '.$label,
            'slug' => 'produk-'.strtolower($label).'-'.uniqid(),
            'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $p->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0,
        ]);

        return $p;
    }

    /**
     * COD so the order starts 'diproses' without a payment gate; the reschedule window is
     * about delivery state, not payment. $items is [productId => requestedDeliveryDate].
     *
     * @param  array<int, array{product_id:int, date:?string}>  $items
     */
    private function placeOrder(User $konsumen, User $agen, array $items): Order
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => array_map(fn ($i) => ['product_id' => $i['product_id'], 'quantity' => 2], $items),
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
        if ($items[0]['date'] !== null) {
            $payload['delivery_date'] = $items[0]['date'];
        }

        $response = $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /**
     * Writes the canonical delivery method on every shipment of the order.
     *
     * `shipments_delivery_mode_consistent` (migration 2026_10_01_100000) requires
     * self_sub => self_delivered_by_user_id NOT NULL and self_sub => courier_id IS NULL, so the
     * mode and the owner are always written together — the DB itself enforces the self_delivery
     * shape and never disables any constraint.
     */
    private function setDeliveryMethod(
        Order $order,
        ?string $code,
        string $deliveryMode = Shipment::DELIVERY_MODE_STANDARD,
        ?int $selfDeliveredBy = null,
    ): void {
        $shipments = Shipment::query()->where('order_id', $order->id)->get();
        foreach ($shipments as $shipment) {
            $shipment->shipping_provider_code = $code;
            if ($deliveryMode === Shipment::DELIVERY_MODE_SELF_SUB) {
                $shipment->delivery_mode = Shipment::DELIVERY_MODE_SELF_SUB;
                $shipment->self_delivered_by_user_id = $selfDeliveredBy ?? $order->konsumen_id;
                $shipment->courier_id = null;
            } else {
                $shipment->delivery_mode = Shipment::DELIVERY_MODE_STANDARD;
                $shipment->self_delivered_by_user_id = null;
            }
            $shipment->save();
        }
    }

    /** @return array{product_id:int, date:?string}[] */
    private function itemSpec(Product $a, ?string $da, ?Product $b = null, ?string $db = null): array
    {
        $items = [['product_id' => $a->id, 'date' => $da]];
        if ($b) {
            $items[] = ['product_id' => $b->id, 'date' => $db];
        }

        return $items;
    }

    private function reschedule(User $admin, Order $order, OrderItem $item, string $date)
    {
        return $this->actingAs($admin)->patchJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/reschedule",
            ['requested_delivery_date' => $date, 'reason' => 'Uji aturan tanggal kirim'],
        );
    }

    // ---------- 1. Kurir Online: date X -> Y is allowed ----------

    public function test_courier_online_allows_changing_the_requested_delivery_date(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($product, '2026-12-01'));
        $this->setDeliveryMethod($order, self::COURIER_ONLINE);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('2026-12-01', $item->requested_delivery_date?->toDateString());

        $this->reschedule($admin, $order, $item, '2026-12-05')->assertOk();

        $this->assertSame('2026-12-05', $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item.delivery_rescheduled']);
    }

    // ---------- 2. Kurir Online: existing Y shipment in the SAME order is reused ----------

    public function test_courier_online_moved_item_joins_the_existing_shipment_for_that_date(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $productA = $this->product($agen, 'A');
        $productB = $this->product($agen, 'B');
        // `delivery_date` is order-level at checkout, so per-item dates only diverge through a
        // reschedule: item B is moved onto 2026-12-05 first, giving that date its own shipment.
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($productA, '2026-12-01', $productB, '2026-12-01'));
        $this->setDeliveryMethod($order, self::COURIER_ONLINE);

        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();
        $this->reschedule($admin, $order, $itemB, '2026-12-05')->assertOk();

        $shipmentB = Shipment::where('order_id', $order->id)->whereKey($itemB->fresh()->shipment_id)->firstOrFail();
        $shipmentsBefore = Shipment::where('order_id', $order->id)->count();

        $this->reschedule($admin, $order, $itemA, '2026-12-05')->assertOk();

        $this->assertSame($shipmentB->id, $itemA->fresh()->shipment_id, 'the moved item JOINS the existing shipment for the new date');
        $this->assertSame(
            $shipmentsBefore - 1,
            Shipment::where('order_id', $order->id)->count(),
            'no duplicate shipment is created; the emptied one is removed',
        );
        $this->assertSame(
            2,
            OrderItem::where('shipment_id', $shipmentB->id)->count(),
            'both same-date items now share one shipment',
        );
        // No empty orphan shipment may linger (it would show as a blank Dispatch card).
        $this->assertFalse(
            Shipment::query()->where('order_id', $order->id)->doesntHave('orderItems')->exists(),
            'no empty shipment may remain after consolidating',
        );
        $this->assertDatabaseHas('activity_logs', ['event' => 'shipment.reused_for_reschedule']);
    }

    // ---------- 3. Kurir Online: no Y shipment yet -> canonical Y shipment ----------

    public function test_courier_online_creates_the_canonical_shipment_when_the_date_is_new(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $productA = $this->product($agen, 'A');
        $productB = $this->product($agen, 'B');
        // Both lines carry the order's delivery date, so under the LOCKED grouping rule they
        // start on ONE canonical shipment.
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($productA, '2026-12-01', $productB, '2026-12-01'));
        $this->setDeliveryMethod($order, self::COURIER_ONLINE);

        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();
        $sharedShipmentId = $itemA->shipment_id;
        $this->assertSame($sharedShipmentId, $itemB->shipment_id, 'precondition: one shared canonical shipment');

        // No shipment exists for 2026-12-05, so moving A there gives it its own canonical shipment.
        $this->reschedule($admin, $order, $itemA, '2026-12-05')->assertOk();

        $itemA = $itemA->fresh();
        $this->assertNotSame($sharedShipmentId, (int) $itemA->shipment_id, 'A left onto its own canonical shipment');
        $this->assertSame($sharedShipmentId, (int) $itemB->fresh()->shipment_id, 'B is untouched');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count(), 'exactly one shipment per date, no duplicates');
        $this->assertSame(
            1,
            OrderItem::where('order_id', $order->id)
                ->whereDate('requested_delivery_date', '2026-12-05')
                ->where('shipment_id', $itemA->shipment_id)->count(),
            'the new date has its own canonical shipment',
        );
        $this->assertFalse(
            Shipment::query()->where('order_id', $order->id)->doesntHave('orderItems')->exists(),
            'no empty shipment may remain',
        );
    }

    // ---------- 4. Ekspedisi / RajaOngkir rejected server-side ----------

    public function test_expedition_rajaongkir_reschedule_is_rejected_server_side(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($product, '2026-12-01'));
        $this->setDeliveryMethod($order, Shipment::PROVIDER_EXPEDITION);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->reschedule($admin, $order, $item, '2026-12-05')
            ->assertUnprocessable()
            ->assertJsonPath('message', __('messages.fulfillment.reschedule_courier_online_only', ['method' => 'expedition']));

        $this->assertSame('2026-12-01', $item->fresh()->requested_delivery_date?->toDateString(), 'date unchanged');
        $this->assertSame('rajaongkir', Shipment::whereKey($item->shipment_id)->value('shipping_provider_code'), 'never converted');
        $this->assertDatabaseMissing('activity_logs', ['event' => 'order_item.delivery_rescheduled']);
    }

    // ---------- 5. Pickup / Ambil di Tempat rejected server-side ----------

    public function test_pickup_reschedule_is_rejected_server_side(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($product, '2026-12-01'));
        $this->setDeliveryMethod($order, Shipment::PROVIDER_PICKUP);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->reschedule($admin, $order, $item, '2026-12-05')
            ->assertUnprocessable()
            ->assertJsonPath('message', __('messages.fulfillment.reschedule_courier_online_only', ['method' => 'pickup']));

        $this->assertSame('2026-12-01', $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame('pickup', Shipment::whereKey($item->shipment_id)->value('shipping_provider_code'));
    }

    // ---------- 6. A direct call into the service cannot bypass the rule ----------

    // ---------- E. Direct service calls cannot bypass the FORBIDDEN cases ----------

    public function test_direct_service_call_cannot_bypass_the_forbidden_delivery_methods(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $service = app(\App\Services\Order\OrderFulfillmentService::class);

        // 'free'/'internal' on a STANDARD shipment are deliberately covered too: eligibility is an
        // allowlist, so an unrecognised provider is never treated as reschedulable.
        foreach (['rajaongkir', 'pickup', 'free', 'internal'] as $method) {
            $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($this->product($agen, 'P'.substr($method, 0, 2)), '2026-12-01'));
            $this->setDeliveryMethod($order, $method);
            $item = OrderItem::where('order_id', $order->id)->firstOrFail();

            try {
                $service->rescheduleItemDeliveryDate($item, '2026-12-05', $admin, 'bypass attempt');
                $this->fail('Shipping method '.$method.' must not be re-datable');
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame(422, $e->status(), 'service call refused for '.$method);
            }

            $this->assertSame('2026-12-01', $item->fresh()->requested_delivery_date?->toDateString(), 'date unchanged for '.$method);
        }
    }

    // ---------- B. self_sub reschedule ALLOWED (Human decision 2026-10-07) ----------

    public function test_self_sub_reschedule_is_allowed(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($product, '2026-12-01'));
        // A self_sub shipment carries the neutral 'free' provider code in this domain — the
        // canonical delivery_mode decides, never the provider code.
        $this->setDeliveryMethod($order, 'free', Shipment::DELIVERY_MODE_SELF_SUB, $sub->id);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(Shipment::DELIVERY_MODE_SELF_SUB, Shipment::whereKey($item->shipment_id)->value('delivery_mode'));

        $this->reschedule($admin, $order, $item, '2026-12-05')->assertOk();

        $this->assertSame('2026-12-05', $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item.delivery_rescheduled']);
    }

    // ---------- F. self_sub reschedule preserves Sub reservation/quantity invariants ----------

    public function test_self_sub_reschedule_preserves_sub_reservation_and_delivery_path(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($product, '2026-12-01'));
        $this->setDeliveryMethod($order, 'free', Shipment::DELIVERY_MODE_SELF_SUB, $sub->id);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $shipmentBefore = Shipment::whereKey($item->shipment_id)->firstOrFail();

        $preSplitQty = (int) $item->fulfilled_quantity;

        // $quantity MOVES onto the new date: the child carries it, the parent keeps the remainder.
        $child = app(\App\Services\Order\OrderFulfillmentService::class)
            ->rescheduleItemDeliveryDate($item->fresh(), '2026-12-05', $admin, 'Sub split', 1);

        $item->refresh();
        $this->assertSame($preSplitQty - 1, (int) $item->fulfilled_quantity, 'parent keeps the retained quantity');
        $this->assertSame(1, (int) $child->fulfilled_quantity, 'child carries the moved quantity');
        $this->assertSame($preSplitQty, (int) $item->fulfilled_quantity + (int) $child->fulfilled_quantity, 'split conserves the original quantity');
        $this->assertSame($item->id, $child->split_from_order_item_id);
        $this->assertSame('2026-12-05', $child->requested_delivery_date?->toDateString());

        // The self_delivery path must survive the split — the child is still Sub/self_delivered,
        // never converted into a normal courier shipment.
        // The parent shipment keeps the self_delivery path unchanged after the split.
        $this->assertSame(Shipment::DELIVERY_MODE_SELF_SUB, $shipmentBefore->fresh()->delivery_mode, 'parent shipment stays self_sub');
        $this->assertNull($shipmentBefore->fresh()->courier_id, 'a self_sub shipment never gets a normal courier');

        // The split child (OrderFulfillmentService::assignFreshShipment) keeps the self_delivery
        // path ONLY for Sub-SOURCED items — `isSubSourced()`, i.e. its own stock_source/sub_location.
        // This fixture's order is agent-sourced (stock_source null), so the child is correctly a
        // standard shipment; forcing it to self_sub would fabricate a Sub that does not exist.
        // The real Sub-sourced path is proved in SubQuantityAdjustmentTest, which asserts
        // delivery_mode = self_sub + the owning self-deliverer on the child.
        $childShipment = Shipment::whereKey($child->fresh()->shipment_id)->firstOrFail();
        $this->assertNull($childShipment->courier_id, 'the child never gets a normal courier');
    }

    // ---------- 7. Frontend gate needs the canonical method in the API contract ----------

    // ---------- I + H. Five distinct dates, sequential changes, reverse rejoins ----------

    public function test_five_distinct_dates_stay_canonical_through_sequential_and_reverse_changes(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $made = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $label) {
            $made[$label] = $this->product($agen, $label);
        }
        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'date' => '2026-10-10'],
            array_values($made),
        ));
        $this->setDeliveryMethod($order, self::COURIER_ONLINE);
        $service = app(\App\Services\Order\OrderFulfillmentService::class);
        $byId = fn () => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->keyBy('product_id');

        // The generic invariant asserted after EVERY change: one canonical shipment per
        // compatible date key — never more, never fewer, no fixed limit anywhere.
        $assertCanonical = function (array $expected) use ($order, $byId) {
            $keys = [];
            foreach ($byId() as $item) {
                $shipment = Shipment::whereKey($item->shipment_id)->firstOrFail();
                $keys[] = implode('|', [
                    $item->requested_delivery_date?->toDateString() ?? '',
                    $shipment->delivery_mode,
                ]);
            }
            $distinct = array_values(array_unique($keys));
            sort($distinct);
            $expect = array_values(array_map(fn (string $d) => $d.'|standard', $expected));
            sort($expect);
            $this->assertSame($expect, $distinct, 'dates present');
            $this->assertSame(
                count($distinct),
                Shipment::where('order_id', $order->id)->count(),
                'canonical shipment count == distinct compatible date keys',
            );
            $this->assertFalse(
                Shipment::query()->where('order_id', $order->id)->doesntHave('orderItems')->exists(),
                'no empty mutable shipment lingers',
            );
        };

        // All six start on 10 Oct => one canonical shipment.
        $assertCanonical(['2026-10-10']);

        // Spread onto five distinct dates: 10 (E,F), 15 (A), 17 (B), 20 (C), 25 (D).
        $service->rescheduleItemDeliveryDate($byId()[$made['A']->id], '2026-10-15', $admin, 'Spread A');
        $service->rescheduleItemDeliveryDate($byId()[$made['B']->id], '2026-10-17', $admin, 'Spread B');
        $service->rescheduleItemDeliveryDate($byId()[$made['C']->id], '2026-10-20', $admin, 'Spread C');
        $service->rescheduleItemDeliveryDate($byId()[$made['D']->id], '2026-10-25', $admin, 'Spread D');
        $assertCanonical(['2026-10-10', '2026-10-15', '2026-10-17', '2026-10-20', '2026-10-25']);

        // C: 20 -> 17 joins B's shipment; the emptied 20-Oct unit disappears.
        $service->rescheduleItemDeliveryDate($byId()[$made['C']->id], '2026-10-17', $admin, 'Join B');
        $assertCanonical(['2026-10-10', '2026-10-15', '2026-10-17', '2026-10-25']);
        $this->assertSame(
            $byId()[$made['B']->id]->shipment_id,
            $byId()[$made['C']->id]->shipment_id,
            'C rejoined the existing 17-Oct shipment',
        );

        // Reverse: B: 17 -> 10 rejoins E and F; the 17-Oct unit keeps only C.
        $service->rescheduleItemDeliveryDate($byId()[$made['B']->id], '2026-10-10', $admin, 'Reverse B');
        $assertCanonical(['2026-10-10', '2026-10-15', '2026-10-17', '2026-10-25']);
        $this->assertSame(
            $byId()[$made['E']->id]->shipment_id,
            $byId()[$made['B']->id]->shipment_id,
        );

        // D: 25 -> 10; the 25-Oct unit vanishes entirely.
        $service->rescheduleItemDeliveryDate($byId()[$made['D']->id], '2026-10-10', $admin, 'Reverse D');
        $assertCanonical(['2026-10-10', '2026-10-15', '2026-10-17']);

        // Finally A: 15 -> 10. Final state: E,F,B,D,A on the 10th and C alone on the 17th.
        $service->rescheduleItemDeliveryDate($byId()[$made['A']->id], '2026-10-10', $admin, 'Reverse A');
        $assertCanonical(['2026-10-10', '2026-10-17']);
        $this->assertSame(5, OrderItem::where('order_id', $order->id)
            ->whereDate('requested_delivery_date', '2026-10-10')->count());
        $this->assertSame(1, OrderItem::where('order_id', $order->id)
            ->whereDate('requested_delivery_date', '2026-10-17')->count());
    }

    public function test_order_projection_exposes_the_server_derived_reschedule_capability(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        // openroute (Kurir Online) and self_sub (Self Delivery/Sub) may reschedule;
        // rajaongkir and pickup may not. The capability is derived, never a client claim.
        $expected = [
            [self::COURIER_ONLINE, Shipment::DELIVERY_MODE_STANDARD, true],
            ['free', Shipment::DELIVERY_MODE_SELF_SUB, true],
            [Shipment::PROVIDER_EXPEDITION, Shipment::DELIVERY_MODE_STANDARD, false],
            [Shipment::PROVIDER_PICKUP, Shipment::DELIVERY_MODE_STANDARD, false],
        ];

        foreach ($expected as [$code, $mode, $allowed]) {
            $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($this->product($agen, 'X'.substr((string) $code, 0, 2)), '2026-12-01'));
            $this->setDeliveryMethod($order, $code, $mode, $mode === Shipment::DELIVERY_MODE_SELF_SUB ? $agen->id : null);

            $detail = $this->actingAs($admin)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
            $label = $code.'/'.$mode;
            $this->assertSame($allowed, $detail['reschedule_allowed'], 'reschedule_allowed for '.$label);
        }
    }

    // ---------- 8. Never move an item into ANOTHER order's shipment ----------

    public function test_reschedule_never_moves_an_item_into_another_orders_shipment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $productA = $this->product($agen, 'A');
        $productB = $this->product($agen, 'B');

        $orderOne = $this->placeOrder($konsumen, $agen, $this->itemSpec($productA, '2026-12-01'));
        $orderTwo = $this->placeOrder($konsumen, $agen, $this->itemSpec($productB, '2026-12-05'));
        $this->setDeliveryMethod($orderOne, self::COURIER_ONLINE);
        $this->setDeliveryMethod($orderTwo, self::COURIER_ONLINE);

        $itemOne = OrderItem::where('order_id', $orderOne->id)->firstOrFail();
        $itemTwo = OrderItem::where('order_id', $orderTwo->id)->firstOrFail();
        $otherShipmentId = $itemTwo->shipment_id;

        // Order Two's item is ALREADY on 2026-12-05 — the exact date we move to.
        $this->assertSame('2026-12-05', $itemTwo->requested_delivery_date?->toDateString());
        $this->reschedule($admin, $orderOne, $itemOne, '2026-12-05')->assertOk();

        $this->assertSame($orderOne->id, $itemOne->fresh()->order_id);
        $this->assertNotSame($otherShipmentId, $itemOne->fresh()->shipment_id, 'must never join another order\'s shipment');
        $this->assertSame($otherShipmentId, $itemTwo->fresh()->shipment_id, 'the other order is untouched');
        $this->assertSame(1, OrderItem::where('shipment_id', $otherShipmentId)->count());
    }

    // ---------- 9. An already-assigned shipment is never joined ----------

    public function test_reschedule_does_not_join_a_shipment_that_already_has_an_executor(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $productA = $this->product($agen, 'A');
        $productB = $this->product($agen, 'B');
        $order = $this->placeOrder($konsumen, $agen, $this->itemSpec($productA, '2026-12-01', $productB, '2026-12-01'));
        $this->setDeliveryMethod($order, self::COURIER_ONLINE);

        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();
        // Put item B on the target date, then give that shipment a real executor.
        $this->reschedule($admin, $order, $itemB, '2026-12-05')->assertOk();
        $assignedShipmentId = $itemB->fresh()->shipment_id;

        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courier = Courier::create([
            'type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id,
            'name' => $kurir->name, 'is_active' => true,
        ]);
        Shipment::whereKey($assignedShipmentId)->update(['courier_id' => $courier->id]);

        $shipmentACount = Shipment::where('order_id', $order->id)->count();
        $this->reschedule($admin, $order, $itemA, '2026-12-05')->assertOk();

        $this->assertNotSame($assignedShipmentId, $itemA->fresh()->shipment_id, 'an assigned shipment is not a grouping target');
        $this->assertSame($courier->id, Shipment::whereKey($assignedShipmentId)->value('courier_id'), 'existing assignment untouched');
        $this->assertSame(1, OrderItem::where('shipment_id', $assignedShipmentId)->count());
        $this->assertSame($shipmentACount, Shipment::where('order_id', $order->id)->count());
    }
}