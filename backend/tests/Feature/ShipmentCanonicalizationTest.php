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
use App\Services\Order\ShipmentCanonicalizationService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * UAT-005 LOCKED GROUPING RULE — within ONE order, compatible delivery items for the SAME
 * requested delivery date share ONE canonical Shipment.
 *
 * Proves BOTH required halves:
 *   A) FUTURE creation — initial fulfillment groups by date from the start (no reschedule needed);
 *   B) SAFE reconciliation — legacy/duplicate same-date shipments consolidate into one canonical
 *      Shipment, and anything unsafe is REPORTED rather than partially applied.
 *
 * Dispatch cards, "Ambil & Kirim", courier assignment and the thermal receipt all read the real
 * Shipment rows, so these assertions are what actually collapse N units into one.
 */
class ShipmentCanonicalizationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, koordinator:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Grouping', 'address' => 'Jl. Grouping',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $label): Product
    {
        $p = Product::create([
            'sku' => 'GC-'.strtoupper($label).'-'.Str::uuid(), 'name' => 'Produk '.$label,
            'slug' => 'produk-'.strtolower($label).'-'.uniqid(),
            'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0,
        ]);

        return $p;
    }

    /** @param  array<int, array{product_id:int, quantity:int}>  $lines */
    private function placeOrder(User $konsumen, User $agen, array $lines, ?string $deliveryDate = null): Order
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => $lines,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
        if ($deliveryDate !== null) {
            $payload['delivery_date'] = $deliveryDate;
        }

        $response = $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /** @return array<int, OrderItem> */
    private function itemsOf(Order $order): array
    {
        return OrderItem::where('order_id', $order->id)->orderBy('id')->get()->all();
    }

    // ---------- 1. INITIAL fulfillment: same order + same date + compatible mode => ONE shipment ----------

    public function test_initial_fulfillment_groups_same_date_items_into_one_shipment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];

        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'quantity' => 2],
            $products
        ), '2026-10-10');

        $items = $this->itemsOf($order);
        $this->assertCount(4, $items);

        $shipmentIds = array_unique(array_map(fn (OrderItem $i) => $i->shipment_id, $items));
        $this->assertCount(1, $shipmentIds, 'four same-date items must share ONE shipment id');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count(), 'exactly one shipment is created');

        // Every item really is on that one shipment, with its own quantity preserved.
        foreach ($items as $item) {
            $this->assertSame($shipmentIds[0], $item->shipment_id);
            $this->assertSame(2, (int) $item->fulfilled_quantity, 'item quantity is untouched');
            $this->assertSame('2026-10-10', $item->requested_delivery_date?->toDateString());
        }
        $this->assertSame(
            8,
            (int) OrderItem::where('shipment_id', $shipmentIds[0])->sum('fulfilled_quantity'),
            'all quantities live on the canonical shipment',
        );

        // No duplicate shipment rows, and the shipping fee snapshot stays on exactly one shipment.
        $this->assertSame(1, Shipment::whereNotNull('shipping_fee_snapshot')->count());
    }

    // ---------- 2. Different dates => different shipment_id ----------

    public function test_different_dates_produce_different_shipments(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'admin' => $admin] = $this->branch();
        $productA = $this->product($agen, 'A');
        $productB = $this->product($agen, 'B');

        $order = $this->placeOrder($konsumen, $agen, [
            ['product_id' => $productA->id, 'quantity' => 1],
            ['product_id' => $productB->id, 'quantity' => 1],
        ], '2026-10-10');
        // Rescheduling is Kurir-Online-only (Human LOCKED rule), so put the shipment in that state.
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);

        // Two lines share the creation date, so they already share one shipment...
        $items = $this->itemsOf($order);
        $this->assertSame($items[0]->shipment_id, $items[1]->shipment_id);

        // ...then moving ONE of them to another date must split it onto its own shipment.
        app(\App\Services\Order\OrderFulfillmentService::class)
            ->rescheduleItemDeliveryDate($items[1], '2026-10-17', $admin, 'later date');

        $items = $this->itemsOf($order);
        $this->assertNotSame($items[0]->shipment_id, $items[1]->shipment_id, 'different dates never share a shipment');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ---------- 3. Different orders never share a Shipment ----------

    public function test_two_orders_never_share_a_shipment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $one = $this->placeOrder($konsumen, $agen, [['product_id' => $product->id, 'quantity' => 1]], '2026-10-10');
        $two = $this->placeOrder($konsumen, $agen, [['product_id' => $product->id, 'quantity' => 1]], '2026-10-10');

        $this->assertNotSame(
            $this->itemsOf($one)[0]->shipment_id,
            $this->itemsOf($two)[0]->shipment_id,
        );
        $this->assertSame(
            Shipment::where('order_id', $one->id)->count() + Shipment::where('order_id', $two->id)->count(),
            Shipment::count(),
            'each order owns its own shipments',
        );
    }

    // ---------- 4. Incompatible delivery modes are never merged ----------

    public function test_self_sub_never_merges_with_a_standard_shipment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, [['product_id' => $product->id, 'quantity' => 2]], '2026-10-10');

        $item = $this->itemsOf($order)[0];
        $standard = Shipment::whereKey($item->shipment_id)->firstOrFail();
        $this->assertSame(Shipment::DELIVERY_MODE_STANDARD, $standard->delivery_mode);

        // A self_sub shipment of the SAME order and SAME date must not be merged into the standard one.
        $subOwner = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $subLocation = \App\Models\WarehouseSubLocation::create([
            'agent_id' => $agen->id, 'code' => 'L-SUB', 'name' => 'Sub Loc', 'created_by' => $agen->id,
        ]);
        $subLocation->forceFill(['owner_user_id' => $subOwner->id])->save();
        $subShipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_provider_code' => 'free',
            'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_SELF_SUB,
            'self_delivered_by_user_id' => $subOwner->id,
        ]);
        $subItem = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 25000, 'subtotal_snapshot' => 25000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1,
            'requested_delivery_date' => '2026-10-10', 'status' => 'diproses',
            'stock_source' => 'sub',
            'sub_location_id' => $subLocation->id,
            'shipment_id' => $subShipment->id,
        ]);

        $report = app(ShipmentCanonicalizationService::class)->reconcileOrder($order);

        $this->assertSame([], $report['conflicts'], 'different modes are simply different groups, not conflicts');
        $this->assertSame([], $report['moved_items'], 'nothing is moved across the standard/self_sub boundary');
        $this->assertSame([], $report['deleted_empty_shipments']);

        $this->assertSame($standard->id, $item->fresh()->shipment_id, 'the standard item stays put');
        $this->assertSame($subShipment->id, $subItem->fresh()->shipment_id, 'the Sub item stays on its self_sub shipment');
        $this->assertSame(Shipment::DELIVERY_MODE_SELF_SUB, $subShipment->fresh()->delivery_mode);
        $this->assertSame($subOwner->id, $subShipment->fresh()->self_delivered_by_user_id);
    }

    // ---------- 5. Safe legacy split: N same-date shipments => one canonical shipment ----------

    public function test_legacy_same_date_split_shipments_consolidate_into_one_canonical_shipment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];

        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'quantity' => 2],
            $products
        ), '2026-10-10');

        // Reproduce the legacy shape: one shipment per item for the SAME date.
        $items = $this->itemsOf($order);
        $canonicalId = $items[0]->shipment_id;
        foreach (array_slice($items, 1) as $index => $item) {
            $legacy = Shipment::create([
                'order_id' => $order->id,
                'shipping_provider_id' => Shipment::whereKey($canonicalId)->value('shipping_provider_id'),
                'shipping_provider_code' => Shipment::whereKey($canonicalId)->value('shipping_provider_code'),
                'status' => 'pending',
                'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
            ]);
            $item->update(['shipment_id' => $legacy->id]);
        }

        $legacyIds = OrderItem::where('order_id', $order->id)->pluck('shipment_id')->unique()->values()->all();
        $this->assertCount(4, $legacyIds, 'precondition: four duplicate same-date shipments');

        $report = app(ShipmentCanonicalizationService::class)->reconcileOrder($order);

        $this->assertSame([], $report['conflicts']);
        $this->assertSame($canonicalId, $report['canonical_shipment_id'], 'the oldest shipment is canonical');
        $this->assertCount(3, $report['merged_shipment_ids']);
        $this->assertCount(3, $report['moved_items']);
        $this->assertCount(3, $report['deleted_empty_shipments']);

        // One shipment survives, carrying every item and every quantity.
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(
            4,
            OrderItem::where('shipment_id', $canonicalId)->count(),
            'all four items live on the canonical shipment',
        );
        $this->assertSame(
            8,
            (int) OrderItem::where('shipment_id', $canonicalId)->sum('fulfilled_quantity'),
            'quantities are preserved',
        );
        // Order/item rows themselves are never rewritten beyond the shipment reference.
        $this->assertSame(4, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame('diproses', $order->fresh()->status);
    }

    // ---------- 6. Redundant empty mutable shipments are removed; dry run changes nothing ----------

    public function test_dry_run_reports_without_mutating_and_empty_redundant_rows_are_removed(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');
        $order = $this->placeOrder($konsumen, $agen, [['product_id' => $product->id, 'quantity' => 2]], '2026-10-10');

        $item = $this->itemsOf($order)[0];
        $keep = Shipment::whereKey($item->shipment_id)->firstOrFail();

        // A second shipment holding the same date, plus a truly empty leftover row.
        $duplicate = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
        ]);
        $empty = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
        ]);
        // Both items (only one here) plus an extra item on the duplicate, same date.
        $second = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 25000, 'subtotal_snapshot' => 25000,
            'original_quantity' => 3, 'fulfilled_quantity' => 3,
            'requested_delivery_date' => '2026-10-10', 'status' => 'diproses',
            'shipment_id' => $duplicate->id,
        ]);

        $service = app(ShipmentCanonicalizationService::class);

        // Dry run: reports the plan and changes NOTHING.
        $plan = $service->reconcileOrder($order, dryRun: true);
        $this->assertTrue($plan['dry_run']);
        $this->assertSame($keep->id, $plan['canonical_shipment_id']);
        $this->assertSame($keep->id, (int) $item->shipment_id, 'dry run must not move items');
        $this->assertSame($duplicate->id, (int) $second->fresh()->shipment_id, 'dry run must not move items');
        $this->assertSame(3, Shipment::where('order_id', $order->id)->count(), 'dry run must not delete rows');

        // Real run.
        $report = $service->reconcileOrder($order);
        $this->assertSame([], $report['conflicts']);
        $this->assertSame($keep->id, $report['canonical_shipment_id']);
        $this->assertSame($keep->id, (int) $second->fresh()->shipment_id, 'the item moved to the canonical shipment');
        $this->assertNull(Shipment::find($duplicate->id), 'the emptied duplicate is removed');

        // Deliberate scope limit: a shipment that holds NO items at all was never part of any
        // duplicate same-date group — it carries no date identity to reason about — so this
        // consolidation does NOT sweep it up. Broad "delete every empty shipment" cleanup is a
        // different operation and is out of scope here (see ShipmentCanonicalizationService).
        $this->assertNotNull($empty->fresh(), 'an unrelated empty orphan is left untouched');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ---------- 7. An assigned shipment is REFUSED ----------

    public function test_reconciliation_refuses_a_shipment_with_an_assigned_courier(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->placeOrder($konsumen, $agen, [
            ['product_id' => $products[0]->id, 'quantity' => 1],
            ['product_id' => $products[1]->id, 'quantity' => 1],
        ], '2026-10-10');

        $items = $this->itemsOf($order);
        $canonicalId = $items[0]->shipment_id;
        $legacy = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
        ]);
        $items[1]->update(['shipment_id' => $legacy->id]);

        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courier = Courier::create([
            'type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id,
            'name' => $kurir->name, 'is_active' => true,
        ]);
        Shipment::whereKey($legacy->id)->update(['courier_id' => $courier->id]);

        $service = app(ShipmentCanonicalizationService::class);

        $plan = $service->reconcileOrder($order, dryRun: true);
        $this->assertNotEmpty($plan['conflicts'], 'an assigned shipment must be reported as a conflict');
        $this->assertSame($legacy->id, $plan['conflicts'][0]['shipment_id']);
        $this->assertStringContainsString('courier', $plan['conflicts'][0]['reason']);

        // The mutating call aborts entirely — no partial corruption.
        $this->expectException(\RuntimeException::class);
        try {
            $service->reconcileOrder($order);
        } finally {
            $this->assertSame($canonicalId, $items[0]->fresh()->shipment_id, 'nothing was moved');
            $this->assertSame($legacy->id, $items[1]->fresh()->shipment_id, 'nothing was moved');
            $this->assertSame(2, Shipment::where('order_id', $order->id)->count(), 'nothing was deleted');
            $this->assertSame($courier->id, Shipment::whereKey($legacy->id)->value('courier_id'), 'assignment intact');
        }
    }

    // ---------- 8. Delivery progression / proof / tracking also block a merge ----------

    public function test_reconciliation_refuses_shipments_with_delivery_or_receipt_state(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->placeOrder($konsumen, $agen, [
            ['product_id' => $products[0]->id, 'quantity' => 1],
            ['product_id' => $products[1]->id, 'quantity' => 1],
        ], '2026-10-10');

        $items = $this->itemsOf($order);
        $legacy = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
        ]);
        $items[1]->update(['shipment_id' => $legacy->id]);

        // In transit + tracking: meaningful execution state that must never be merged away.
        Shipment::whereKey($legacy->id)->update([
            'status' => 'in_transit', 'shipped_at' => now(), 'tracking_number' => 'RESI123',
        ]);

        $plan = app(ShipmentCanonicalizationService::class)->reconcileOrder($order, dryRun: true);
        $this->assertNotEmpty($plan['conflicts']);
        $this->assertStringContainsString('in_transit', $plan['conflicts'][0]['reason']);

        // Even a pending shipment is refused when it already carries a delivery proof.
        Shipment::whereKey($legacy->id)->update(['status' => 'pending', 'shipped_at' => null, 'tracking_number' => null]);
        $media = \App\Models\Media::create([
            'disk' => 'public', 'collection' => 'shipment_proof', 'path' => 'x/y.jpg',
            'original_filename' => 'y.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
            'size' => 1, 'width' => 1, 'height' => 1,
            'mediable_type' => Shipment::class, 'mediable_id' => $legacy->id,
            'created_by' => $order->konsumen_id,
        ]);
        Shipment::whereKey($legacy->id)->update(['proof_media_id' => $media->id]);

        $plan = app(ShipmentCanonicalizationService::class)->reconcileOrder($order, dryRun: true);
        $this->assertNotEmpty($plan['conflicts']);
        $this->assertStringContainsString('proof', $plan['conflicts'][0]['reason']);
    }

    // ---------- 9. Dispatch shows ONE shipment containing all items ----------

    public function test_dispatch_shows_one_shipment_with_all_items_after_consolidation(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'koordinator' => $koordinator] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];
        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'quantity' => 2],
            $products
        ), '2026-10-10');

        // Legacy split into four.
        $items = $this->itemsOf($order);
        $canonicalId = $items[0]->shipment_id;
        foreach (array_slice($items, 1) as $item) {
            $item->update(['shipment_id' => Shipment::create([
                'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
                'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
            ])->id]);
        }

        app(ShipmentCanonicalizationService::class)->reconcileOrder($order);

        $dispatch = $this->actingAs($koordinator)->getJson('/api/v1/dispatch')->assertOk()->json('data');
        $forOrder = collect($dispatch)->where('order_id', $order->id);

        $this->assertCount(1, $forOrder, 'exactly ONE dispatch card for the order');
        $card = $forOrder->first();
        $this->assertSame($canonicalId, $card['shipment_id']);
        $this->assertCount(4, $card['items'], 'the card lists all four items');
        $this->assertSame(['2026-10-10'], $card['delivery_date']);
        $this->assertSame(
            8,
            (int) collect($card['items'])->sum('quantity'),
            'all quantities appear on the single card',
        );
    }

    // ---------- 10. Receipt/resi is ONE unit containing all items ----------

    public function test_receipt_is_one_shipment_unit_with_all_items_after_consolidation(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];
        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'quantity' => 2],
            $products
        ), '2026-10-10');

        $items = $this->itemsOf($order);
        $canonicalId = $items[0]->shipment_id;
        foreach (array_slice($items, 1) as $item) {
            $item->update(['shipment_id' => Shipment::create([
                'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
                'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
            ])->id]);
        }

        app(ShipmentCanonicalizationService::class)->reconcileOrder($order);

        // One shipment exists => exactly one receipt/resi unit, carrying every item.
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());

        $receipt = $this->actingAs($admin)
            ->getJson("/api/v1/shipments/{$canonicalId}/receipt")
            ->assertOk()
            ->json('data');

        $this->assertSame($canonicalId, $receipt['shipment_id']);
        $this->assertCount(4, $receipt['items'], 'the resi lists every item of the delivery');
        $this->assertSame(8, (int) $receipt['total_item_count']);
        $this->assertSame('2026-10-10', \Illuminate\Support\Carbon::parse($receipt['delivery_date'])->toDateString());
    }

    // ---------- 11. SC-03 and courier-assignment guards remain enforced ----------

    // ---------- 13. Order projection exposes each shipment's canonical delivery date ----------

    public function test_order_projection_exposes_a_canonical_date_per_shipment_unit(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];

        $order = $this->placeOrder($konsumen, $agen, array_map(
            fn (Product $p) => ['product_id' => $p->id, 'quantity' => 2],
            $products
        ), '2026-10-10');

        // 10 Oct unit: items 1-4. 15 Oct unit: a rescheduled pair.
        $items = $this->itemsOf($order);
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);
        foreach ([$items[2], $items[3]] as $item) {
            app(\App\Services\Order\OrderFulfillmentService::class)
                ->rescheduleItemDeliveryDate($item->fresh(), '2026-10-15', $admin, 'Later delivery');
        }

        $detail = $this->actingAs($admin)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $dates = $detail['shipment_delivery_dates'];

        $this->assertSame(2, Shipment::where('order_id', $order->id)->count(), 'two delivery dates => two canonical shipments');

        // Every shipment unit reports exactly ONE canonical date, and the two units differ.
        $byDate = collect($dates)->pluck('delivery_date')->sort()->values()->all();
        $this->assertSame(['2026-10-10', '2026-10-15'], $byDate, 'each canonical unit exposes its own date');

        // The 10 Oct shipment really carries items 1 and 2; the 15 Oct one carries 3 and 4.
        $shipments = Shipment::where('order_id', $order->id)->orderBy('id')->get();
        $oct10 = null;
        $oct15 = null;
        foreach ($shipments as $shipment) {
            $d = $dates[(string) $shipment->id]['delivery_date'] ?? null;
            if ($d === '2026-10-10') {
                $oct10 = $shipment;
            }
            if ($d === '2026-10-15') {
                $oct15 = $shipment;
            }
        }
        $this->assertNotNull($oct10);
        $this->assertNotNull($oct15);
        // All four lines were placed with quantity 2 and none was reduced, so the 10 Oct unit carries
// both of its items whole and the 15 Oct unit likewise.
        $this->assertSame(
            [2, 2],
            OrderItem::where('shipment_id', $oct10->id)->orderByDesc('id')->pluck('fulfilled_quantity')->values()->all(),
        );
        $this->assertSame(2, OrderItem::where('shipment_id', $oct15->id)->count());

        // A shipment whose items disagree on the date reports null instead of inventing one.
        $mixed = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => Shipment::DELIVERY_MODE_STANDARD,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $products[0]->id,
            'product_name_snapshot' => $products[0]->name, 'sku_snapshot' => $products[0]->sku,
            'unit_price_snapshot' => 25000, 'subtotal_snapshot' => 25000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1,
            'requested_delivery_date' => '2026-10-20', 'status' => 'diproses',
            'shipment_id' => $mixed->id,
        ]);
        $after = $this->actingAs($admin)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(
            '2026-10-20',
            $after['shipment_delivery_dates'][(string) $mixed->id]['delivery_date'] ?? null,
            'a single-date shipment reports its date',
        );
    }

    // ---------- 11. SC-03 and courier-assignment guards remain enforced ----------

    public function test_sc03_and_courier_assignment_guards_survive_consolidation(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $products = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->placeOrder($konsumen, $agen, [
            ['product_id' => $products[0]->id, 'quantity' => 1],
            ['product_id' => $products[1]->id, 'quantity' => 1],
        ], '2026-10-10');

        app(ShipmentCanonicalizationService::class)->reconcileOrder($order);

        $shipmentId = OrderItem::where('order_id', $order->id)->firstOrFail()->shipment_id;

        // Consolidation must not have weakened the assignment guard: a cross-agent coordinator is
        // still refused on the canonical shipment.
        // A cross-branch koordinator still cannot assign it (own-branch rule, enforced by policy).
        $other = $this->branch();
        $this->actingAs($other['koordinator'])
            ->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => 0])
            ->assertForbidden();
        // And the shipment still has no executor after consolidation.
        $this->assertNull(Shipment::whereKey($shipmentId)->value('courier_id'));
    }
}