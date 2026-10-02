<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Thermal shipping-receipt print: GET /shipments/{shipment}/receipt.
 * mode (pre_pickup/post_pickup) is derived server-side from Shipment.
 * shipped_at — this project has no dedicated picked_up_at column; the
 * diproses->dikirim transition IS the pickup event (see CourierService::
 * syncShipmentProgress). Printing is a pure read: it must never move order/
 * item/shipment status, touch shipped_at, or affect payment/commission.
 */
class ShipmentReceiptPrintTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Kurir', 'phone' => '0899123456']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        return compact('agen', 'korsal', 'sales', 'admin', 'keuangan', 'konsumen', 'superAdmin', 'kurir');
    }

    private function makeProduct(User $agen, string $name = 'Kue Resi', int $price = 40000, int $stockQty = 10): Product
    {
        $product = Product::create(['sku' => 'SKU-'.Str::upper(Str::random(6)),
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    private function placeOrder(User $konsumen, array $lines, string $paymentMethod = 'cod'): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethod,
            'items' => $lines,
            'recipient_name' => 'Budi Penerima', 'recipient_phone' => '081234567890', 'address_line' => 'Jl. Sudirman No. 10',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function shipmentIdFor(Order $order): int
    {
        return Shipment::query()->where('order_id', $order->id)->value('id');
    }

    /* ---------------------------------------------------------------
     * Permission matrix
     * ------------------------------------------------------------- */

    public function test_super_admin_can_print_pre_pickup_receipt(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $response = $this->actingAs($branch['superAdmin'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk()->assertJsonPath('data.mode', 'pre_pickup');
    }

    public function test_agent_can_print_own_network_pre_pickup_receipt(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')
            ->assertOk()->assertJsonPath('data.mode', 'pre_pickup');
    }

    public function test_admin_can_print_own_agent_pre_pickup_receipt(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $this->actingAs($branch['admin'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')
            ->assertOk()->assertJsonPath('data.mode', 'pre_pickup');
    }

    public function test_agent_cannot_print_other_agent_receipt(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $this->actingAs($otherBranch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')
            ->assertStatus(403);
    }

    public function test_admin_cannot_print_other_agent_receipt(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $this->actingAs($otherBranch['admin'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')
            ->assertStatus(403);
    }

    public function test_courier_cannot_print_other_courier_shipment(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        // First kurir picks up (self-assigns) — second kurir never held this shipment.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($secondKurir)->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertStatus(403);
        $this->actingAs($branch['kurir'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
    }

    public function test_kurir_cannot_print_an_unassigned_shipment(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        // No courier assigned yet — the kurir hasn't picked it up, so it isn't "theirs" to print.
        $this->actingAs($branch['kurir'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')->assertStatus(403);
    }

    public function test_keuangan_korsal_and_sales_never_get_shipment_print_permission(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        foreach (['keuangan', 'korsal', 'sales'] as $role) {
            $this->actingAs($branch[$role])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertStatus(403);
        }
        $this->actingAs($branch['konsumen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
    }

    /* ---------------------------------------------------------------
     * Pre-pickup vs post-pickup content
     * ------------------------------------------------------------- */

    public function test_pre_pickup_receipt_shows_waiting_pickup(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk()
            ->assertJsonPath('data.mode', 'pre_pickup')
            ->assertJsonPath('data.picked_up_at', null)
            ->assertJsonPath('data.courier_name', null);
    }

    public function test_post_pickup_receipt_shows_picked_up(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $response = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt");
        $response->assertOk()->assertJsonPath('data.mode', 'post_pickup');
    }

    public function test_post_pickup_receipt_shows_actual_courier(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")
            ->assertOk()->assertJsonPath('data.courier_name', 'Budi Kurir');
    }

    public function test_post_pickup_receipt_shows_actual_pickup_time_not_current_time_on_reprint(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->travelTo(now()->setTime(10, 32));
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();
        $pickedUpAt = Shipment::find($shipmentId)->shipped_at;

        // Reprint two hours later — the pickup time on the receipt must not move to "now".
        $this->travelTo(now()->addHours(2));
        $response = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt");
        $response->assertOk();
        $this->assertSame($pickedUpAt->toJSON(), $response->json('data.picked_up_at'));

        $this->travelBack();
    }

    /* ---------------------------------------------------------------
     * Data fidelity: snapshots, all items, delivery date
     * ------------------------------------------------------------- */

    public function test_receipt_uses_shipping_address_snapshot_not_current_konsumen_profile(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        // Konsumen later edits their own profile/name — must never leak onto an already-placed order's receipt.
        $branch['konsumen']->update(['name' => 'Nama Baru Setelah Order']);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk()
            ->assertJsonPath('data.recipient_name', 'Budi Penerima')
            ->assertJsonPath('data.address_line', 'Jl. Sudirman No. 10');
    }

    public function test_receipt_uses_sku_snapshot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $originalSku = $product->sku;

        // Product master SKU changes after the order — receipt must keep the historical snapshot.
        $product->update(['sku' => 'SKU-CHANGED-LATER']);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk();
        $this->assertSame($originalSku, $response->json('data.items.0.sku'));
    }

    public function test_receipt_shows_all_order_items_on_this_shipment(): void
    {
        $branch = $this->makeAgentBranch();
        $productA = $this->makeProduct($branch['agen'], 'Brownies Premium');
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $productA->id, 'quantity' => 2]]);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Brownies Premium', $response->json('data.items.0.product_name'));
        $this->assertSame(2, $response->json('data.items.0.quantity'));
        $this->assertSame(2, $response->json('data.total_item_count'));
    }

    /* ---------------------------------------------------------------
     * Printing never mutates state
     * ------------------------------------------------------------- */

    public function test_printing_does_not_change_order_status(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $statusBefore = $order->status;

        $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')->assertOk();

        $this->assertSame($statusBefore, $order->fresh()->status);
    }

    public function test_printing_does_not_change_item_status(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $statusBefore = $item->status;

        $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt')->assertOk();

        $this->assertSame($statusBefore, $item->fresh()->status);
    }

    public function test_printing_does_not_change_shipment_status(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);
        $statusBefore = Shipment::find($shipmentId)->status;

        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();

        $this->assertSame($statusBefore, Shipment::find($shipmentId)->status);
    }

    public function test_reprint_does_not_change_picked_up_at(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();
        $pickedUpAt = Shipment::find($shipmentId)->shipped_at;

        // Print it three times ("reprint") — shipped_at must never move.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
        }

        $this->assertTrue($pickedUpAt->equalTo(Shipment::find($shipmentId)->shipped_at));
    }

    public function test_reprint_never_creates_a_new_courier_assignment_or_recomputes_shipping_fee(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();
        $courierIdBefore = Shipment::find($shipmentId)->courier_id;
        $feeBefore = $order->fresh()->shipping_fee_amount;

        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();

        $this->assertSame($courierIdBefore, Shipment::find($shipmentId)->courier_id);
        $this->assertEquals($feeBefore, $order->fresh()->shipping_fee_amount);
    }

    public function test_reprint_is_logged_as_a_reprint_in_activity_log(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Shipment::class, 'subject_id' => $shipmentId, 'event' => 'shipment.receipt_printed',
        ]);

        $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertOk();
        $this->assertSame(2, \App\Models\ActivityLog::where('subject_type', Shipment::class)
            ->where('subject_id', $shipmentId)->where('event', 'shipment.receipt_printed')->count());
    }

    public function test_cancelled_shipment_cannot_print_when_not_allowed(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['konsumen'])->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Berubah pikiran'])->assertOk();
        $this->assertSame('dibatalkan', $order->fresh()->status);

        foreach (['superAdmin', 'agen', 'admin'] as $role) {
            $this->actingAs($branch[$role])->getJson("/api/v1/shipments/{$shipmentId}/receipt")->assertStatus(403);
        }
    }

    /* ---------------------------------------------------------------
     * No financial ledger leakage
     * ------------------------------------------------------------- */

    public function test_receipt_never_exposes_commission_or_fee_fields(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen']);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2000,
        ])->assertOk();
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk();
        $json = json_encode($response->json());
        foreach (['agent_fee', 'sales_fee', 'courier_fee', 'commission'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertArrayNotHasKey('latitude', $response->json('data'));
        $this->assertArrayNotHasKey('longitude', $response->json('data'));
    }

    public function test_receipt_prints_its_shipment_fee_and_tracking_identity(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Resi scope', 50000);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]]);
        $shipment = Shipment::findOrFail($this->shipmentIdFor($order));
        $shipment->update(['shipping_fee_snapshot' => 12345, 'tracking_number' => 'PC-RESI-123']);

        $receipt = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$shipment->id}/receipt")
            ->assertOk()->json('data');
        $this->assertSame('12345.00', $receipt['shipping_fee_amount']);
        $this->assertSame('PC-RESI-123', $receipt['tracking_number']);
        $this->assertSame(1, $receipt['total_item_count']);
        $this->assertArrayNotHasKey('remaining_amount', $receipt);
    }

    public function test_cod_receipt_shows_order_due_only_when_order_is_one_delivery_group(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue COD', 50000);
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $product->id, 'quantity' => 1]], 'cod');

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/receipt');
        $response->assertOk()->assertJsonPath('data.payment.is_cod', true);
        $this->assertEquals($order->fresh()->total_amount, $response->json('data.payment.cod_amount_due'));
        $this->assertArrayNotHasKey('dp_outstanding_amount', $response->json('data.payment'));
    }

    public function test_multi_date_cod_resi_does_not_mislabel_the_order_balance_as_group_due(): void
    {
        $branch = $this->makeAgentBranch();
        $products = [$this->makeProduct($branch['agen'], 'Resi A', 50000), $this->makeProduct($branch['agen'], 'Resi B', 60000)];
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $products[0]->id, 'quantity' => 1], ['product_id' => $products[1]->id, 'quantity' => 1]], 'cod');
        $items = $order->items()->orderBy('id')->get();
        $firstShipment = $items[0]->shipment;
        $secondShipment = $firstShipment->replicate();
        $secondShipment->save();
        $items[0]->update(['requested_delivery_date' => now()->addDays(2)->toDateString(), 'shipment_id' => $firstShipment->id]);
        $items[1]->update(['requested_delivery_date' => now()->addDays(5)->toDateString(), 'shipment_id' => $secondShipment->id]);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/shipments/'.$firstShipment->id.'/receipt')->assertOk();
        $response->assertJsonPath('data.total_item_count', 1)
            ->assertJsonPath('data.payment.is_cod', true)
            ->assertJsonPath('data.payment.cod_amount_due', null)
            ->assertJsonPath('data.shipping_fee_amount', $firstShipment->fresh()->shipping_fee_snapshot);
        $this->assertArrayNotHasKey('remaining_amount', $response->json('data.payment'));
        $this->assertArrayNotHasKey('dp_outstanding_amount', $response->json('data.payment'));
    }

    public function test_multi_date_dp_resi_places_initial_credit_on_earliest_group_only(): void
    {
        $branch = $this->makeAgentBranch();
        $products = [$this->makeProduct($branch['agen'], 'DP Resi A', 50000), $this->makeProduct($branch['agen'], 'DP Resi B', 60000)];
        $order = $this->placeOrder($branch['konsumen'], [['product_id' => $products[0]->id, 'quantity' => 1], ['product_id' => $products[1]->id, 'quantity' => 1]], 'cod');
        $items = $order->items()->orderBy('id')->get();
        $firstShipment = $items[0]->shipment;
        $secondShipment = $firstShipment->replicate();
        $secondShipment->save();
        $earliestDate = now()->addDays(2)->toDateString();
        $laterDate = now()->addDays(5)->toDateString();
        $items[0]->update(['requested_delivery_date' => $earliestDate, 'shipment_id' => $firstShipment->id]);
        $items[1]->update(['requested_delivery_date' => $laterDate, 'shipment_id' => $secondShipment->id]);
        $downPayment = PaymentMethod::where('code', 'down_payment')->firstOrFail();
        $order->update(['payment_method_id' => $downPayment->id, 'dp_amount' => 40000, 'paid_amount' => 40000, 'remaining_amount' => $order->total_amount - 40000, 'payment_status' => 'partially_paid']);

        $first = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$firstShipment->id}/receipt")->assertOk();
        $first->assertJsonPath('data.delivery_date', $earliestDate)
            ->assertJsonPath('data.payment.initial_dp_amount', '40000.00')
            ->assertJsonPath('data.payment.initial_dp_credit', 40000);
        $later = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$secondShipment->id}/receipt")->assertOk();
        $later->assertJsonPath('data.delivery_date', $laterDate)
            ->assertJsonPath('data.payment.initial_dp_amount', null)
            ->assertJsonPath('data.payment.initial_dp_credit', null);
        $this->assertArrayNotHasKey('dp_outstanding_amount', $later->json('data.payment'));
        $this->assertArrayNotHasKey('remaining_amount', $later->json('data.payment'));

        $duplicateEarliestShipment = $firstShipment->replicate();
        $duplicateEarliestShipment->save();
        OrderItem::create([
            'order_id' => $order->id, 'shipment_id' => $duplicateEarliestShipment->id,
            'product_id' => $products[0]->id, 'product_name_snapshot' => $products[0]->name,
            'sku_snapshot' => $products[0]->sku, 'unit_price_snapshot' => 50000,
            'subtotal_snapshot' => 50000, 'original_quantity' => 1, 'fulfilled_quantity' => 1,
            'requested_delivery_date' => $earliestDate, 'status' => 'diproses',
        ]);
        $duplicateReceipt = $this->actingAs($branch['agen'])->getJson("/api/v1/shipments/{$duplicateEarliestShipment->id}/receipt")->assertOk();
        $duplicateReceipt->assertJsonPath('data.delivery_date', $earliestDate)
            ->assertJsonPath('data.payment.initial_dp_amount', null)
            ->assertJsonPath('data.payment.initial_dp_credit', null);
    }
}
