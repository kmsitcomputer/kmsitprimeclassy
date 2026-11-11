<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AdminNotification;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner workflow (Koordinator Kurir → Gudang → Koordinator → Kurir), no Admin approval in the routine path:
 *  - Koordinator asks Gudang; Gudang hands over what it can, with evidence and a reason for every short line;
 *  - the Koordinator receives and assigns/ships what was handed over, whatever the Admin has or has not decided;
 *  - a shortage raises PEMENUHAN KURANG on the Order detail for the Admin, per product;
 *  - the Admin decides reschedule or cancel for the REMAINDER only, never for delivered/dispatched units;
 *  - the Admin keeps SC-03 (add a new line) on an order with an open shortage.
 */
class FulfillmentShortageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** Order of two products (A x10, B x5), one Kurir Toko shipment per product, already `diproses` with a Stock Request. */
    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'kurir' => User::factory()->kurir()->create(['agent_id' => $agent->id]),
            'foreignAdmin' => User::factory()->admin()->create(),
        ];
        $b['courier'] = Courier::create(['type' => 'internal', 'user_id' => $b['kurir']->id, 'agent_id' => $agent->id, 'name' => 'K1', 'is_active' => true]);

        $products = [];
        foreach (['A' => 10, 'B' => 5] as $name => $qty) {
            $products[$name] = Product::create(['sku' => "SH{$name}-".uniqid(), 'name' => "Kue {$name}", 'slug' => "sh-{$name}-".uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $products[$name]->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $qty]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $products[$name]->id, 'stock_type' => 'transit', 'quantity' => 30]);
        }
        $spare = Product::create(['sku' => 'SHC-'.uniqid(), 'name' => 'Kue Baru', 'slug' => 'sh-c-'.uniqid(), 'has_variations' => false, 'base_price' => 2000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $spare->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $spare->id, 'stock_type' => 'transit', 'quantity' => 30]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        $order = Order::create([
            'order_no' => 'SH-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 15000, 'total_amount' => 15000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $items = [];
        $shipments = [];
        foreach (['A' => 10, 'B' => 5] as $name => $qty) {
            $shipments[$name] = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
            $items[$name] = OrderItem::create([
                'order_id' => $order->id, 'shipment_id' => $shipments[$name]->id, 'product_id' => $products[$name]->id,
                'product_name_snapshot' => $products[$name]->name, 'sku_snapshot' => $products[$name]->sku, 'unit_price_snapshot' => 1000,
                'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima',
                'requested_delivery_date' => now()->addDays(2)->toDateString(),
            ]);
        }
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();

        return [$b, $order->fresh(), $items, $shipments['A'], $request, $products, $spare];
    }

    private function demand(StockRequest $request, OrderItem $item): StockRequestItem
    {
        return StockRequestItem::query()->where('stock_request_id', $request->id)->where('order_item_id', $item->id)->firstOrFail();
    }

    private function handover(array $b, StockRequest $request, array $items, array $shortages = [], bool $withProof = true)
    {
        $payload = ['items' => $items];
        if ($shortages) {
            $payload['shortages'] = $shortages;
        }
        if ($withProof) {
            $payload['proof'] = UploadedFile::fake()->image('serah-terima.jpg', 40, 40);
        }

        return $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $payload, ['Accept' => 'application/json']);
    }

    private function receive(array $b, array $proposal, array $accepted)
    {
        $lines = [];
        foreach ($proposal['items'] as $i => $line) {
            $lines[] = ['item_id' => $line['id'], 'accepted' => $accepted[$i], 'short' => $line['quantity'] - $accepted[$i], 'damaged' => 0];
        }

        return $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => $lines]);
    }

    /* ───────────── Gudang: evidence + reason, no Admin ───────────── */

    public function test_gudang_handover_requires_evidence_and_a_reason_for_every_short_line(): void
    {
        [$b, , $items, , $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);
        $bLine = $this->demand($request, $items['B']);

        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]], withProof: false)->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6]])->assertStatus(422);
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'x']])->assertStatus(422);
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]], [['item_id' => $a->id, 'reason' => 'dobel']])->assertStatus(422);
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]], [['item_id' => $bLine->id, 'reason' => 'ab']])->assertStatus(422);
        $this->assertSame(0, StockRequestProposal::withoutGlobalScopes()->count(), 'a refused submission leaves nothing behind');

        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'Stok produksi belum siap']], [['item_id' => $bLine->id, 'reason' => 'Bahan baku habis']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.shortages');

        $this->assertNotNull(StockRequestProposal::withoutGlobalScopes()->firstOrFail()->handoverProof);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count(), 'no stock moves before the Koordinator receipt');
    }

    public function test_only_gudang_of_the_branch_hands_over(): void
    {
        [$b, , $items, , $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);
        foreach (['admin', 'koordinator', 'agent', 'foreignAdmin'] as $role) {
            $status = $this->actingAs($b[$role])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals",
                $this->withHandoverProof(['items' => [['item_id' => $a->id, 'quantity' => 10]]]), ['Accept' => 'application/json'])->status();
            $this->assertContains($status, [403, 404], "{$role} must be refused");
        }
        $this->assertSame(0, StockRequestProposal::withoutGlobalScopes()->count());
    }

    public function test_gudang_with_nothing_available_still_reports_the_shortage_without_a_proposal_or_stock_movement(): void
    {
        [$b, $order, $items, , $request] = $this->fixture();
        $bLine = $this->demand($request, $items['B']);

        $this->handover($b, $request, [], [['item_id' => $bLine->id, 'reason' => 'Bahan baku habis']], withProof: false)->assertCreated();
        $this->handover($b, $request, [], [['item_id' => $bLine->id, 'reason' => 'x']], withProof: false)->assertStatus(422);

        $this->assertSame(0, StockRequestProposal::withoutGlobalScopes()->count(), 'nothing to receive, so no proposal');
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
        $this->assertSame(2, AdminNotification::query()->where('type', 'fulfillment.shortage')->where('order_id', $order->id)->count(), 'Admin + Agen');

        $block = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillment_shortage');
        $this->assertSame([5, 0, 5, 'pending_decision'], [$block['items'][0]['ordered_qty'], $block['items'][0]['handed_over_qty'], $block['items'][0]['short_qty'], $block['items'][0]['status']]);
        $this->assertSame([], $block['proofs']);
    }

    /* ───────────── Gudang queue shows the Koordinator's request (real data only) ───────────── */

    public function test_gudang_queue_shows_who_asked_and_when_and_can_filter_to_the_koordinators_requests(): void
    {
        [$b, $order, , , $request] = $this->fixture();

        // Before any request: the order is in the queue but carries no invented requester.
        $row = collect($this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertFalse($row['fulfillment_request']['requested_by_coordinator']);
        $this->assertNull($row['fulfillment_request']['coordinator']);
        $this->assertNull($row['fulfillment_request']['requested_at']);
        $this->assertSame([], $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses?requested=1')->assertOk()->json('data'));

        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/orders/{$order->id}/request-fulfillment", ['note' => 'Butuh besok pagi'])->assertOk();

        $queue = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses?requested=1')->assertOk();
        $row = $queue->json('data.0');
        $this->assertSame($order->id, $row['id']);
        $this->assertTrue($row['fulfillment_request']['requested_by_coordinator']);
        $this->assertSame($b['koordinator']->id, $row['fulfillment_request']['coordinator']['id']);
        $this->assertSame($b['koordinator']->name, $row['fulfillment_request']['coordinator']['name']);
        $this->assertNotNull($row['fulfillment_request']['requested_at']);
        $this->assertSame('Butuh besok pagi', $row['fulfillment_request']['note']);
        $this->assertSame([15, 0, 0, 15], [$row['fulfillment_request']['requested_qty'], $row['fulfillment_request']['handed_over_qty'], $row['fulfillment_request']['awaiting_receipt_qty'], $row['fulfillment_request']['outstanding_qty']]);
        $this->assertSame(1, $queue->json('meta.requested_total'));

        // After Gudang hands over, the queue shows it as awaiting the Koordinator's receipt (no invented status).
        $items = OrderItem::where('order_id', $order->id)->get()->keyBy('product_name_snapshot');
        $a = $this->demand($request, $items['Kue A']);
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]])->assertCreated();
        $row = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses?requested=1')->assertOk()->json('data.0');
        $this->assertSame(10, $row['fulfillment_request']['awaiting_receipt_qty']);

        // Other roles never reach the queue.
        foreach (['admin', 'koordinator', 'kurir'] as $role) {
            $this->actingAs($b[$role])->getJson('/api/v1/warehouse/orders/diproses')->assertForbidden();
        }
    }

    /* ───────────── Owner simulation: 3 products x 1 on ONE shipment, 2 handed over, 1 short ───────────── */

    /** Same shape checkout produces: three different products, one unit each, all on a single shipment. */
    private function threeProductOrder(): array
    {
        [$b] = $this->fixture();
        $agent = $b['agent'];
        $products = [];
        foreach (['P1', 'P2', 'P3'] as $name) {
            $products[$name] = Product::create(['sku' => "O3{$name}-".uniqid(), 'name' => "Produk {$name}", 'slug' => "o3-{$name}-".uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $products[$name]->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 1]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $products[$name]->id, 'stock_type' => 'transit', 'quantity' => 30]);
        }
        $order = Order::create([
            'order_no' => 'O3-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 3000, 'total_amount' => 3000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
        $items = [];
        foreach ($products as $name => $product) {
            $items[$name] = OrderItem::create([
                'order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id,
                'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000,
                'subtotal_snapshot' => 1000, 'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
                'requested_delivery_date' => now()->addDays(2)->toDateString(),
            ]);
        }
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/orders/{$order->id}/request-fulfillment")->assertOk();

        return [$b, $order->fresh(), $items, $shipment, $request];
    }

    public function test_owner_simulation_two_of_three_products_are_assigned_and_the_third_is_reported_short_to_the_admin(): void
    {
        [$b, $order, $items, $shipment, $request] = $this->threeProductOrder();
        $d = fn (string $k) => $this->demand($request, $items[$k]);

        // Gudang hands over P1 and P2 (full, with evidence) and reports P3 as not available (reason, no evidence needed for it).
        $proposal = $this->handover($b, $request, [['item_id' => $d('P1')->id, 'quantity' => 1], ['item_id' => $d('P2')->id, 'quantity' => 1]],
            [['item_id' => $d('P3')->id, 'reason' => 'Stok P3 habis di Gudang']])->assertCreated()->json('data');
        $this->receive($b, $proposal, [1, 1])->assertOk();

        // Admin is told about P3 straight away — before any assignment or shipment.
        $notes = AdminNotification::query()->where('type', 'fulfillment.shortage')->where('order_id', $order->id)->get();
        $this->assertEqualsCanonicalizing([$b['admin']->id, $b['agent']->id], $notes->pluck('recipient_user_id')->all());
        $this->assertSame(0, ActivityLog::query()->where('event', 'shipment.courier_assigned')->count(), 'notified before any assignment');

        // The Koordinator assigns the shipment: P1 and P2 are assigned, P3 is carved onto its own pending shipment.
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->assertSame($b['courier']->id, $shipment->fresh()->courier_id);
        $this->assertSame($shipment->id, $items['P1']->fresh()->shipment_id);
        $this->assertSame($shipment->id, $items['P2']->fresh()->shipment_id);
        $p3Shipment = Shipment::find($items['P3']->fresh()->shipment_id);
        $this->assertNotSame($shipment->id, $p3Shipment->id, 'P3 left the assigned shipment');
        $this->assertNull($p3Shipment->courier_id, 'P3 is not assigned');
        $this->assertSame('pending', $p3Shipment->status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'shipment.courier_assigned')->count(), 'assigned once');
        $this->assertSame(0, ActivityLog::query()->where('event', 'stock_request.admin_exception_approval')->count(), 'no Admin approval anywhere');

        // Assigning again is an idempotent replay; the unaccepted P3 can never be assigned or shipped.
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->assertSame($shipment->id, $items['P1']->fresh()->shipment_id);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$p3Shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertStatus(422);

        // The courier ships the two received products; P3 stays untouched.
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame(['dikirim', 'dikirim', 'diproses'], [$items['P1']->fresh()->status, $items['P2']->fresh()->status, $items['P3']->fresh()->status]);

        // No negative stock, no double movement: exactly P1 and P2 moved Transit -> Shipping.
        $this->assertSame(2, (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'shipping')->sum('quantity'));
        $this->assertSame(0, (int) WarehouseStock::withoutGlobalScopes()->where('quantity', '<', 0)->count());
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('stock_type', 'shipping')->count());

        // Admin detail: only P3, ordered 1 / handed over 0 / short 1, reason, evidence of the handover of P1+P2.
        $block = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillment_shortage');
        $this->assertTrue($block['has_open_shortage']);
        $this->assertCount(1, $block['items']);
        $row = $block['items'][0];
        $this->assertSame(['Produk P3', 1, 0, 1, 'Stok P3 habis di Gudang', 'pending_decision'], [$row['product_name'], $row['ordered_qty'], $row['handed_over_qty'], $row['short_qty'], $row['reason'], $row['status']]);
        $this->assertSame($items['P3']->id, $row['order_item_id']);
        $this->assertCount(1, $block['proofs']);

        // Admin acts on P3 only: reschedule moves P3's date, never the shipped lines; cancel removes only P3.
        $newDate = now()->addDays(20)->toDateString();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$items['P3']->id}/reschedule", ['requested_delivery_date' => $newDate, 'reason' => 'Menunggu stok P3'])->assertOk();
        $this->assertSame($newDate, $items['P3']->fresh()->requested_delivery_date->toDateString());
        foreach (['P1', 'P2'] as $k) {
            $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$items[$k]->id}/reschedule", ['requested_delivery_date' => $newDate, 'reason' => 'x'])->assertStatus(422);
            $this->assertSame('dikirim', $items[$k]->fresh()->status);
        }
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$items['P3']->id}/cancel-undelivered", ['quantity' => 1, 'expected_quantity' => 1, 'reason' => 'Dibatalkan, stok tidak ada'])->assertOk();
        $this->assertSame(0, (int) $items['P3']->fresh()->fulfilled_quantity);
        $this->assertSame([1, 1], [(int) $items['P1']->fresh()->fulfilled_quantity, (int) $items['P2']->fresh()->fulfilled_quantity]);
    }

    public function test_nothing_accepted_still_refuses_assignment_and_leaves_the_shipment_untouched(): void
    {
        [$b, , $items, $shipment] = $this->threeProductOrder();

        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertStatus(422);
        $this->assertNull($shipment->fresh()->courier_id);
        foreach ($items as $item) {
            $this->assertSame($shipment->id, $item->fresh()->shipment_id, 'nothing is moved when nothing was accepted');
        }
    }

    /* ───────────── Notification: PEMENUHAN KURANG ───────────── */

    public function test_a_shortage_raises_one_linked_notification_for_branch_admin_and_agent_only(): void
    {
        [$b, $order, $items, , $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);

        // A complete handover is not a shortage: nothing is raised.
        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]])->assertCreated();
        $this->assertSame(0, AdminNotification::query()->where('type', 'fulfillment.shortage')->count());

        $proposal = $this->handover($b, $request->fresh(), [['item_id' => $this->demand($request, $items['B'])->id, 'quantity' => 3, 'shortage_reason' => 'Rusak saat produksi']])
            ->assertCreated()->json('data');

        $rows = AdminNotification::query()->where('type', 'fulfillment.shortage')->get();
        $this->assertEqualsCanonicalizing([$b['admin']->id, $b['agent']->id], $rows->pluck('recipient_user_id')->all());
        $this->assertTrue($rows->every(fn ($n) => (int) $n->order_id === $order->id && $n->title === 'PEMENUHAN KURANG' && (int) $n->subject_id === $proposal['id']));

        // The Admin sees it linked to the order in their own notification list; nobody else does.
        $this->actingAs($b['admin'])->getJson('/api/v1/notifications/admin')->assertOk()->assertJsonFragment(['title' => 'PEMENUHAN KURANG']);
        foreach (['gudang', 'koordinator', 'konsumen', 'foreignAdmin'] as $role) {
            $this->assertSame(0, AdminNotification::query()->where('type', 'fulfillment.shortage')->where('recipient_user_id', $b[$role]->id)->count());
        }
    }

    /* ───────────── Shipping the handed-over part never waits for the Admin ───────────── */

    public function test_the_handed_over_part_is_assigned_and_shipped_without_any_admin_decision(): void
    {
        [$b, $order, $items, $shipment, $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);

        $proposal = $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'Produksi baru selesai 6 unit']])->assertCreated()->json('data');
        $this->receive($b, $proposal, [6])->assertOk();

        // Koordinator carves the not-yet-handed-over 4 into a later batch, assigns and ships the 6.
        $split = $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/order-items/{$items['A']->id}/split-batch", ['quantity' => 4])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/items/{$items['A']->id}/status", ['status' => 'dikirim'])->assertOk();

        $this->assertSame('dikirim', $items['A']->fresh()->status);
        $this->assertSame(6, $items['A']->fresh()->fulfilled_quantity);
        $this->assertSame(4, $split['quantity']);

        // Nobody with the Admin role had to approve, decide or even see anything for that to happen.
        $this->assertSame(0, ActivityLog::query()->whereIn('event', ['stock_request.admin_exception_approval', 'order_item.delivery_rescheduled', 'order_item.undelivered_cancelled'])->count());

        // The remainder cannot be shipped (nothing handed over for it).
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$split['shipment_id']}/courier", ['courier_id' => $b['courier']->id])->assertStatus(422);
    }

    /* ───────────── Order detail: per-product shortage for the Admin ───────────── */

    public function test_order_detail_shows_accurate_per_product_shortage_to_office_roles_only(): void
    {
        [$b, $order, $items, , $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);
        $bLine = $this->demand($request, $items['B']);

        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.fulfillment_shortage', null);

        $proposal = $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'Produksi baru 6 unit']], [['item_id' => $bLine->id, 'reason' => 'Bahan baku habis']])
            ->assertCreated()->json('data');
        $this->receive($b, $proposal, [6])->assertOk();

        $block = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillment_shortage');
        $this->assertTrue($block['has_open_shortage']);
        $this->assertSame([15, 6, 9], [$block['ordered_qty'], $block['handed_over_qty'], $block['short_qty']]);
        $rows = collect($block['items'])->keyBy('product_name');
        $this->assertSame([10, 6, 4, 'pending_decision', 'Produksi baru 6 unit'], [$rows['Kue A']['ordered_qty'], $rows['Kue A']['handed_over_qty'], $rows['Kue A']['short_qty'], $rows['Kue A']['status'], $rows['Kue A']['reason']]);
        $this->assertSame([5, 0, 5, 'pending_decision', 'Bahan baku habis'], [$rows['Kue B']['ordered_qty'], $rows['Kue B']['handed_over_qty'], $rows['Kue B']['short_qty'], $rows['Kue B']['status'], $rows['Kue B']['reason']]);
        $this->assertSame($b['gudang']->id, $rows['Kue A']['reported_by']['id']);
        $this->assertSame(1, count($block['proofs']));
        $this->assertNotEmpty($block['proofs'][0]['url']);

        foreach (['gudang', 'koordinator', 'konsumen'] as $role) {
            $json = $this->actingAs($b[$role])->getJson("/api/v1/orders/{$order->id}");
            if ($json->status() === 200) {
                $this->assertArrayNotHasKey('fulfillment_shortage', $json->json('data'), "{$role} must not receive the Admin decision block");
            }
        }
    }

    /* ───────────── Admin decisions: only the remainder ───────────── */

    public function test_admin_reschedules_and_cancels_only_the_undispatched_remainder_after_a_partial_shipment(): void
    {
        [$b, $order, $items, $shipment, $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);
        $bLine = $this->demand($request, $items['B']);

        $proposal = $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'Baru 6 unit']], [['item_id' => $bLine->id, 'reason' => 'Bahan habis']])->assertCreated()->json('data');
        $this->receive($b, $proposal, [6])->assertOk();
        $split = $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/order-items/{$items['A']->id}/split-batch", ['quantity' => 4])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/items/{$items['A']->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);

        // Koordinator and Gudang cannot take the Admin's decisions.
        $newDate = now()->addDays(20)->toDateString();
        foreach (['koordinator', 'gudang', 'kurir'] as $role) {
            $this->actingAs($b[$role])->patchJson("/api/v1/orders/{$order->id}/items/{$split['order_item_id']}/reschedule", ['requested_delivery_date' => $newDate, 'reason' => 'x'])->assertForbidden();
            $this->actingAs($b[$role])->postJson("/api/v1/orders/{$order->id}/items/{$bLine->order_item_id}/cancel-undelivered", ['quantity' => 1, 'expected_quantity' => 5, 'reason' => 'x'])->assertForbidden();
        }

        // The shipped 6 are untouchable.
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$items['A']->id}/reschedule", ['requested_delivery_date' => $newDate, 'reason' => 'ulang'])->assertStatus(422);
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$items['A']->id}/cancel-undelivered", ['quantity' => 1, 'expected_quantity' => 6, 'reason' => 'ulang'])->assertStatus(422);

        // Reschedule the 4 remaining of A (allowed although the order is already `dikirim`, because it is a reported shortage).
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$split['order_item_id']}/reschedule", ['requested_delivery_date' => $newDate, 'reason' => 'Menunggu produksi'])->assertOk();
        $this->assertSame($newDate, OrderItem::find($split['order_item_id'])->requested_delivery_date->toDateString());

        // Cancel the whole remainder of B (nothing of it was handed over); a stale/duplicate request changes nothing.
        $cancel = ['quantity' => 5, 'expected_quantity' => 5, 'reason' => 'Bahan baku tidak tersedia'];
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$items['B']->id}/cancel-undelivered", $cancel)->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$items['B']->id}/cancel-undelivered", $cancel)->assertStatus(422);
        $this->assertSame(0, (int) $items['B']->fresh()->fulfilled_quantity);

        // Delivered/dispatched units and their quantities are exactly what they were.
        $this->assertSame([6, 'dikirim'], [$items['A']->fresh()->fulfilled_quantity, $items['A']->fresh()->status]);

        $block = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillment_shortage');
        $statuses = collect($block['items'])->mapWithKeys(fn ($r) => [$r['order_item_id'] => $r['status']]);
        $this->assertSame('rescheduled', $statuses[$split['order_item_id']] ?? null);
        $this->assertSame('cancelled', collect($block['items'])->firstWhere('product_name', 'Kue B')['status']);
        $this->assertSame(1, ActivityLog::query()->where('event', 'order_item.delivery_rescheduled')->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'order_item.undelivered_cancelled')->count());
    }

    public function test_the_reschedule_exception_is_limited_to_reported_shortage_lines(): void
    {
        [$b, $order, $items, $shipment, $request] = $this->fixture();
        $a = $this->demand($request, $items['A']);

        // Complete handover of A, then ship it: B (never reported short) must keep the original `dikirim` window rule.
        $proposal = $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 10]])->assertCreated()->json('data');
        $this->receive($b, $proposal, [10])->assertOk();
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/items/{$items['A']->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$items['B']->id}/reschedule", ['requested_delivery_date' => now()->addDays(20)->toDateString(), 'reason' => 'x'])
            ->assertStatus(422);
    }

    /* ───────────── The Admin keeps SC-03 (adding a new item) ───────────── */

    public function test_admin_can_still_add_a_new_line_to_an_order_with_an_open_shortage(): void
    {
        [$b, $order, $items, , $request, , $spare] = $this->fixture();
        $a = $this->demand($request, $items['A']);

        $this->handover($b, $request, [['item_id' => $a->id, 'quantity' => 6, 'shortage_reason' => 'Baru 6 unit']])->assertCreated();

        $this->actingAs($b['admin'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order->id}/items", ['product_id' => $spare->id, 'quantity' => 2, 'reason' => 'Tambahan pelanggan'])
            ->assertCreated();

        $this->assertSame(1, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->count(), 'the single Stock Request is reused');
        $this->assertSame(3, StockRequestItem::query()->where('stock_request_id', $request->id)->count());
        $newLine = StockRequestItem::query()->where('stock_request_id', $request->id)->where('product_id', $spare->id)->firstOrFail();
        $this->assertSame([2, 0, 2], [$newLine->requested_qty, $newLine->fulfilled_qty, $newLine->remaining_qty]);

        // The shortage block stays accurate: the new line was never reported short and is not part of it.
        $block = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillment_shortage');
        $this->assertSame(['Kue A'], collect($block['items'])->pluck('product_name')->all());
    }
}
