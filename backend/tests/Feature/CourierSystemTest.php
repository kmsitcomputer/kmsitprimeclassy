<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
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
 * Covers three intertwined pieces added together in this task: the courier
 * delivery fee (visibility restricted to agen/super_admin, never sales,
 * never any customer-facing path), per-item delivery-date reschedule as an
 * alternative to cancellation, and the full kurir role/authorization matrix.
 */
class CourierSystemTest extends TestCase
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
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        // Built directly via factory + a manual Courier row rather than round-tripping
        // through POST /api/v1/users + a same-test actingAs() switch: Laravel's Sanctum
        // SPA test guard flips config('auth.defaults.guard') to 'sanctum' the first time
        // auth:sanctum succeeds, and Illuminate\Session\Middleware\AuthenticateSession then
        // compares the session's stored password hash (keyed by that now-different default
        // driver name) against whichever user we actingAs() next — logging them straight
        // back out. UserManagementService's Courier-row auto-creation on account creation is
        // already covered separately by UserManagementTest; this only needs the end state.
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Kurir', 'phone' => '0899123456']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        return compact('agen', 'korsal', 'sales', 'admin', 'konsumen', 'superAdmin', 'kurir');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stockQty): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    private function placeOrder(User $konsumen, Product $product, int $qty): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /** Every order starts with exactly one shipment shared by all its items — see the OrderService::createOrder wiring. */
    private function shipmentIdFor(Order $order): int
    {
        return Shipment::query()->where('order_id', $order->id)->value('id');
    }

    /* ---------------------------------------------------------------
     * Courier fee: configuration + visibility
     * ------------------------------------------------------------- */

    public function test_super_admin_can_set_courier_fee_and_it_is_stored_as_a_third_beneficiary_row(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Kurir', 40000, 10);

        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2000,
        ])->assertOk();

        $this->assertDatabaseHas('product_fees', ['product_id' => $product->id, 'beneficiary_role' => 'courier', 'amount' => 2000]);
    }

    public function test_courier_fee_is_visible_to_super_admin_and_agen_but_not_sales_korsal_admin_or_konsumen(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Fee', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2500,
        ])->assertOk();

        foreach (['superAdmin', 'agen'] as $role) {
            $response = $this->actingAs($branch[$role])->getJson("/api/v1/products/{$product->id}/fees");
            $response->assertOk();
            $this->assertSame(2500, (int) $response->json('data.courier_fee'));
        }

        // Sales can view the fee endpoint at all (agent_fee/sales_fee), but courier_fee must never appear for them.
        $salesResponse = $this->actingAs($branch['sales'])->getJson("/api/v1/products/{$product->id}/fees");
        $salesResponse->assertOk();
        $this->assertArrayNotHasKey('courier_fee', $salesResponse->json('data'));

        // Admin/korsal/konsumen cannot reach the fee endpoint at all.
        foreach (['admin', 'korsal', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])->getJson("/api/v1/products/{$product->id}/fees")->assertStatus(403);
        }
    }

    public function test_courier_fee_snapshot_on_order_item_visible_only_to_super_admin_and_agen(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Snapshot', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 1500,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'courier_fee_amount' => 1500]);

        foreach (['superAdmin', 'agen'] as $role) {
            $view = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$order->id}");
            $view->assertOk();
            $this->assertSame(1500, (int) $view->json('data.items.0.courier_fee_amount'));
        }

        foreach (['sales', 'korsal', 'konsumen'] as $role) {
            $view = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$order->id}");
            $view->assertOk();
            $this->assertArrayNotHasKey('courier_fee_amount', $view->json('data.items.0'));
        }
    }

    public function test_courier_fee_never_appears_on_any_public_or_catalog_endpoint(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Publik', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 1500,
        ])->assertOk();

        $catalog = $this->getJson("/api/v1/products/{$product->slug}");
        $catalog->assertOk();
        $this->assertArrayNotHasKey('courier_fee', $catalog->json('data'));
        $this->assertArrayNotHasKey('courier_fee_amount', $catalog->json('data'));
    }

    public function test_courier_commission_is_recorded_only_at_terkirim_not_at_order_creation(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Delivery', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 3000,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        // Agent/sales fees are earned immediately; courier fee is not, yet.
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'beneficiary_role' => 'agent']);
        $this->assertDatabaseMissing('commissions', ['order_id' => $order->id, 'beneficiary_role' => 'courier']);

        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->assertDatabaseMissing('commissions', ['order_id' => $order->id, 'beneficiary_role' => 'courier']);

        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id, 'beneficiary_role' => 'courier',
            'beneficiary_user_id' => $branch['kurir']->id, 'amount' => 3000,
        ]);
    }

    public function test_kurir_can_see_own_fee_recap_via_commission_summary_but_not_other_beneficiaries_totals(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Recap', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 4000,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $summary = $this->actingAs($branch['kurir'])->getJson('/api/v1/commissions/summary');
        $summary->assertOk();
        $this->assertSame(4000.0, (float) $summary->json('data.total_courier_fee'));
        // Self-scoped: the kurir's own query never aggregates the agent's/sales' cut.
        $this->assertSame(0.0, (float) $summary->json('data.total_agent_fee'));

        // super_admin sees the full recap across every branch by default, and can narrow to one agent.
        $otherBranch = $this->makeAgentBranch();
        $allBranches = $this->actingAs($branch['superAdmin'])->getJson('/api/v1/commissions/summary');
        $allBranches->assertOk();
        $this->assertSame(4000.0, (float) $allBranches->json('data.total_courier_fee'));

        $filtered = $this->actingAs($branch['superAdmin'])->getJson('/api/v1/commissions/summary?agent_id='.$otherBranch['agen']->id);
        $filtered->assertOk();
        $this->assertSame(0.0, (float) $filtered->json('data.total_courier_fee'));
    }

    /* ---------------------------------------------------------------
     * Per-item reschedule vs cancel
     * ------------------------------------------------------------- */

    public function test_admin_can_reschedule_an_items_delivery_date_instead_of_cancelling(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Reschedule', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $newDate = now()->addDays(5)->toDateString();
        $response = $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => $newDate, 'reason' => 'Konsumen minta diundur',
        ]);
        $response->assertOk();
        $this->assertStringStartsWith($newDate, $response->json('data.requested_delivery_date'));

        // Untouched status/quantity — this is a pure reschedule, not a reduction.
        $item->refresh();
        $this->assertSame('diproses', $item->status);
        $this->assertSame(1, $item->fulfilled_quantity);
        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item.delivery_rescheduled']);
    }

    /** Rescheduling only part of a line's quantity splits it into a new OrderItem on its own shipment, leaving the rest untouched. */
    public function test_admin_can_reschedule_only_part_of_an_items_quantity_splitting_it_into_a_new_item(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Split', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 3);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $originalShipmentId = $item->shipment_id;

        $newDate = now()->addDays(5)->toDateString();
        $response = $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => $newDate, 'reason' => '2 dari 3 diundur', 'quantity' => 2,
        ]);
        $response->assertOk();
        $this->assertStringStartsWith($newDate, $response->json('data.requested_delivery_date'));
        $this->assertSame(2, $response->json('data.fulfilled_quantity'));

        $newItemId = $response->json('data.id');
        $this->assertNotSame($item->id, $newItemId);

        // Original row: 1 unit left, untouched date/shipment.
        $item->refresh();
        $this->assertSame(1, $item->fulfilled_quantity);
        $this->assertSame(1, $item->original_quantity);
        $this->assertSame($originalShipmentId, $item->shipment_id);
        $this->assertEquals(40000, (float) $item->subtotal_snapshot);

        // New row: 2 units, new date, its own new shipment.
        $newItem = OrderItem::findOrFail($newItemId);
        $this->assertSame(2, $newItem->fulfilled_quantity);
        $this->assertSame(2, $newItem->original_quantity);
        $this->assertNotSame($originalShipmentId, $newItem->shipment_id);
        $this->assertEquals(80000, (float) $newItem->subtotal_snapshot);
        $this->assertSame($product->id, $newItem->product_id);

        $this->assertEquals(3, OrderItem::where('order_id', $order->id)->sum('fulfilled_quantity'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item.split_for_reschedule']);
    }

    public function test_rescheduling_the_full_quantity_explicitly_does_not_split(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Full Qty', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 2);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $newDate = now()->addDays(4)->toDateString();
        $response = $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => $newDate, 'reason' => 'Semua diundur', 'quantity' => 2,
        ]);
        $response->assertOk();
        $this->assertSame($item->id, $response->json('data.id'));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_rescheduling_with_a_quantity_greater_than_available_is_rejected(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Overflow', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 2);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => now()->addDays(4)->toDateString(), 'reason' => 'Too many', 'quantity' => 3,
        ])->assertStatus(422);
    }

    public function test_reschedule_is_rejected_once_item_has_shipped(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Terlambat', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => now()->addDays(3)->toDateString(), 'reason' => 'Too late',
        ])->assertStatus(422);
    }

    public function test_only_admin_agen_and_super_admin_may_reschedule_never_kurir_sales_or_konsumen(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Otorisasi', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        foreach (['kurir', 'sales', 'konsumen', 'korsal'] as $role) {
            $this->actingAs($branch[$role])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
                'requested_delivery_date' => now()->addDays(2)->toDateString(), 'reason' => 'Not allowed',
            ])->assertStatus(403);
        }

        $this->actingAs($branch['superAdmin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
            'requested_delivery_date' => now()->addDays(2)->toDateString(), 'reason' => 'OK',
        ])->assertOk();
    }

    /* ---------------------------------------------------------------
     * Kurir authorization matrix
     * ------------------------------------------------------------- */

    public function test_kurir_must_belong_to_an_agent_and_only_sees_own_branch_orders(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();

        $product = $this->makeProduct($branch['agen'], 'Kue Branch', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $ownList = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/orders');
        $ownList->assertOk();
        $this->assertTrue(collect($ownList->json('data'))->contains('id', $order->id));

        // A kurir from a different agent's branch never sees this order.
        $otherList = $this->actingAs($otherBranch['kurir'])->getJson('/api/v1/kurir/orders');
        $otherList->assertOk();
        $this->assertFalse(collect($otherList->json('data'))->contains('id', $order->id));

        // Nor can they view it directly.
        $this->actingAs($otherBranch['kurir'])->getJson("/api/v1/orders/{$order->id}")->assertStatus(404);
    }

    public function test_kurir_can_move_diproses_to_dikirim_to_terkirim_but_not_into_diproses(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Alur', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        // Kurir may never push an order INTO diproses — that's still an office decision.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertStatus(403);

        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $this->assertSame('terkirim', $order->fresh()->status);
    }

    public function test_kurir_self_assigns_on_dikirim_and_a_second_kurir_cannot_take_over(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $product = $this->makeProduct($branch['agen'], 'Kue Assign', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();

        $courierId = Courier::where('user_id', $branch['kurir']->id)->value('id');
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'courier_id' => $courierId]);

        // A different kurir in the same branch cannot barge in on an already-claimed delivery.
        $this->actingAs($secondKurir)->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim'])->assertStatus(403);
    }

    public function test_admin_can_proactively_assign_a_courier_and_kurir_cannot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Proaktif', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $courierId = Courier::where('user_id', $branch['kurir']->id)->value('id');
        $shipmentId = $this->shipmentIdFor($order);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => $courierId])->assertStatus(403);

        $this->actingAs($branch['admin'])->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => $courierId])
            ->assertOk()->assertJsonPath('data.couriers.0.name', 'Budi Kurir');

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'courier_id' => $courierId]);
    }

    public function test_kurir_cannot_reach_fulfillment_refund_or_additional_payment_admin_endpoints(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Terlarang', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Kurir tries to cancel',
        ])->assertStatus(403);

        $this->actingAs($branch['kurir'])->getJson('/api/v1/admin/order-refunds')->assertStatus(403);
        $this->actingAs($branch['kurir'])->getJson('/api/v1/admin/additional-payments')->assertStatus(403);
        $this->actingAs($branch['kurir'])->getJson('/api/v1/admin/returns')->assertStatus(403);
        $this->actingAs($branch['kurir'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 1, 'sales_fee' => 1, 'courier_fee' => 1,
        ])->assertStatus(403);
        $this->actingAs($branch['kurir'])->getJson("/api/v1/products/{$product->id}/fees")->assertStatus(403);
    }

    public function test_kurir_order_view_never_exposes_any_monetary_field(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Uang', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $list = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/orders');
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        foreach (['subtotal_amount', 'total_amount', 'payment_status', 'payment_method', 'items'] as $forbiddenTopLevelMoney) {
            if ($forbiddenTopLevelMoney === 'items') {
                continue;
            }
            $this->assertArrayNotHasKey($forbiddenTopLevelMoney, $row);
        }
        foreach ($row['items'] as $itemRow) {
            $this->assertArrayNotHasKey('unit_price_snapshot', $itemRow);
            $this->assertArrayNotHasKey('courier_fee_amount', $itemRow);
            $this->assertArrayNotHasKey('subtotal_snapshot', $itemRow);
        }
    }

    /* ---------------------------------------------------------------
     * Kurir + returns (pengembalian)
     * ------------------------------------------------------------- */

    private function makeDeliveredAndReturnedItem(array $branch, Product $product): array
    {
        $order = $this->placeOrder($branch['konsumen'], $product, 2);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'restock' => true]],
            'reason' => 'Rusak saat kirim',
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $returnItemId = ReturnItem::where('order_item_id', $item->id)->value('id');

        return compact('order', 'item', 'returnItemId');
    }

    public function test_kurir_sees_pengembalian_queue_and_can_confirm_kembali(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Retur', 40000, 10);
        ['item' => $item, 'returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($branch, $product);

        $this->assertSame('pengembalian', $item->fresh()->status);

        $queue = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/returns');
        $queue->assertOk();
        $this->assertCount(1, $queue->json('data'));
        // No money leaks into the kurir's return queue.
        $this->assertArrayNotHasKey('refund_amount', $queue->json('data.0'));
        $this->assertArrayNotHasKey('total_refund_amount', $queue->json('data.0'));

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/confirm", ['received' => true])->assertOk();

        $this->assertSame('kembali', $item->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'return_item.courier_confirmed']);
    }

    /** Kurir can attach a note when claiming the pickup — surfaces on both the kurir queue and the admin return dashboard. */
    public function test_kurir_can_attach_a_condition_note_when_picking_up_a_return(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Retur Note', 40000, 10);
        ['returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($branch, $product);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [
            'note' => 'Kemasan penyok saat diambil',
        ])->assertOk();

        $this->assertDatabaseHas('return_items', [
            'id' => $returnItemId, 'condition_note' => 'Kemasan penyok saat diambil',
        ]);

        $queue = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/returns');
        $queue->assertOk()->assertJsonPath('data.0.items.0.condition_note', 'Kemasan penyok saat diambil');
    }

    public function test_an_unclaimed_return_is_visible_to_every_kurir_but_disappears_once_one_of_them_picks_it_up(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $product = $this->makeProduct($branch['agen'], 'Kue Retur Rebutan', 40000, 10);
        ['returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($branch, $product);

        // Both couriers see the unclaimed return.
        foreach ([$branch['kurir'], $secondKurir] as $actor) {
            $list = $this->actingAs($actor)->getJson('/api/v1/kurir/returns');
            $list->assertOk();
            $this->assertCount(1, $list->json('data'));
        }

        $this->actingAs($secondKurir)->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [])->assertOk();

        // Now claimed by secondKurir — the original kurir no longer sees it in their queue...
        $afterList = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/returns');
        $afterList->assertOk();
        $this->assertCount(0, $afterList->json('data'));

        // ...and cannot pick it up or confirm it either, even though it's still 'pengembalian'.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [])->assertStatus(403);
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/confirm", ['received' => true])
            ->assertStatus(403);

        // secondKurir, who actually holds it, can still confirm it normally.
        $this->actingAs($secondKurir)->patchJson("/api/v1/kurir/returns/{$returnItemId}/confirm", ['received' => true])->assertOk();
    }

    public function test_kurir_can_reject_a_return_pickup_reverting_item_to_terkirim(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Retur Tolak', 40000, 10);
        ['item' => $item, 'returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($branch, $product);

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/confirm", ['received' => false])->assertOk();

        $this->assertSame('terkirim', $item->fresh()->status);
    }

    public function test_kurir_from_another_branch_cannot_confirm_a_return_not_in_their_network(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Retur Cross', 40000, 10);
        ['returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($branch, $product);

        $this->actingAs($otherBranch['kurir'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/confirm", ['received' => true])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------
     * Kurir delivered-orders report
     * ------------------------------------------------------------- */

    public function test_kurir_delivered_report_lists_only_their_own_deliveries_with_no_monetary_fields(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Laporan', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $report = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/reports/delivered');
        $report->assertOk();
        $this->assertCount(1, $report->json('data'));
        $this->assertSame($order->id, $report->json('data.0.id'));
        $this->assertArrayNotHasKey('total_amount', $report->json('data.0'));

        // A second kurir in the same branch who never delivered anything sees an empty report.
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);
        $emptyReport = $this->actingAs($secondKurir)->getJson('/api/v1/kurir/reports/delivered');
        $emptyReport->assertOk();
        $this->assertCount(0, $emptyReport->json('data'));
    }

    /* ---------------------------------------------------------------
     * Courier name/phone visibility on order detail
     * ------------------------------------------------------------- */

    public function test_courier_name_and_phone_appear_on_order_detail_only_once_assigned(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Kontak', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        // Not yet assigned — the couriers list is present but empty for everyone.
        foreach (['konsumen', 'sales', 'korsal', 'admin', 'agen'] as $role) {
            $view = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$order->id}");
            $view->assertOk();
            $this->assertSame([], $view->json('data.couriers'));
        }

        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();

        foreach (['konsumen', 'sales', 'korsal', 'admin', 'agen'] as $role) {
            $view = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$order->id}");
            $view->assertOk();
            $this->assertSame('Budi Kurir', $view->json('data.couriers.0.name'));
            $this->assertSame('0899123456', $view->json('data.couriers.0.phone'));
        }
    }

    /* ---------------------------------------------------------------
     * Multi-courier orders — the actual point of the shipment_id refactor:
     * "satu order bisa beberapa kurir karena ada kemungkinan produk yang bisa
     * di reschedule".
     * ------------------------------------------------------------- */

    /**
     * Every item gets its own Shipment from order creation onward (never a
     * shared one) — so a kurir action on one product never bleeds onto a
     * sibling product, and rescheduling one item never needs to split
     * anything off (there's nothing shared left to split).
     */
    public function test_each_order_item_has_its_own_independent_shipment_from_creation(): void
    {
        $branch = $this->makeAgentBranch();
        $productA = $this->makeProduct($branch['agen'], 'Kue A', 40000, 10);
        $productB = $this->makeProduct($branch['agen'], 'Kue B', 30000, 10);

        $response = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        // Each item already has its own distinct shipment — never shared.
        $this->assertNotNull($itemA->shipment_id);
        $this->assertNotNull($itemB->shipment_id);
        $this->assertNotSame($itemA->shipment_id, $itemB->shipment_id);
        $originalShipmentId = $itemA->fresh()->shipment_id;

        // Rescheduling A never needs to split anything off (nothing was shared) — its shipment stays the same.
        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", [
            'requested_delivery_date' => now()->addDays(5)->toDateString(), 'reason' => 'Konsumen minta diundur',
        ])->assertOk();

        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame($originalShipmentId, $itemA->shipment_id);
        $this->assertNotSame($itemA->shipment_id, $itemB->shipment_id);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'shipment.split_for_reschedule']);

        // A second kurir picks up A's shipment; the original kurir carries B — two different couriers, one order,
        // and — the point of this whole test — picking up/delivering one product never touches the other.
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => 'Second Kurir', 'is_active' => true]);

        $this->actingAs($secondKurir)->patchJson("/api/v1/shipments/{$itemA->shipment_id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$itemB->shipment_id}/status", ['status' => 'dikirim'])->assertOk();

        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame('dikirim', $itemA->status);
        $this->assertSame('dikirim', $itemB->status);

        // The order's own denormalized status reflects the least-progressed shipment.
        $this->assertSame('dikirim', $order->fresh()->status);

        // Deliver both — different couriers, different courier_fee credited to each.
        $this->actingAs($secondKurir)->patch("/api/v1/shipments/{$itemA->shipment_id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status); // B still in transit — order not fully terkirim yet.

        $this->actingAs($branch['kurir'])->patch("/api/v1/shipments/{$itemB->shipment_id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();
        $this->assertSame('terkirim', $order->fresh()->status);

        // Both couriers now show up on the order's courier list.
        $view = $this->actingAs($branch['agen'])->getJson("/api/v1/orders/{$order->id}");
        $view->assertOk();
        $this->assertCount(2, $view->json('data.couriers'));
        $names = collect($view->json('data.couriers'))->pluck('name')->all();
        $this->assertContains('Budi Kurir', $names);
        $this->assertContains('Second Kurir', $names);
    }

    public function test_a_kurir_cannot_mark_terkirim_on_a_shipment_they_do_not_hold_even_within_their_own_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $productA = $this->makeProduct($branch['agen'], 'Kue X', 40000, 10);
        $productB = $this->makeProduct($branch['agen'], 'Kue Y', 30000, 10);

        $response = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();

        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", [
            'requested_delivery_date' => now()->addDays(5)->toDateString(), 'reason' => 'Reschedule',
        ])->assertOk();

        $splitShipmentId = $itemA->fresh()->shipment_id;

        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => 'Second Kurir', 'is_active' => true]);

        // The original kurir self-assigns the SPLIT shipment (A's, now theirs)...
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$splitShipmentId}/status", ['status' => 'dikirim'])->assertOk();

        // ...so a second kurir, even from the same branch, cannot finish delivering it.
        $this->actingAs($secondKurir)->patchJson("/api/v1/shipments/{$splitShipmentId}/status", ['status' => 'terkirim'])->assertStatus(403);
    }

    /* ---------------------------------------------------------------
     * Delivery visibility: diproses is universal, dikirim/terkirim are exclusive
     * ------------------------------------------------------------- */

    public function test_a_diproses_order_stays_visible_to_every_kurir_even_after_a_proactive_admin_assignment(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $product = $this->makeProduct($branch['agen'], 'Kue Universal', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        // Admin proactively pre-assigns a courier to the shipment — the item is still 'diproses', not yet picked up.
        $courierId = Courier::where('user_id', $branch['kurir']->id)->value('id');
        $shipmentId = $this->shipmentIdFor($order);
        $this->actingAs($branch['admin'])->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => $courierId])->assertOk();

        // "Semua order diproses bisa dilihat semua kurir" — the pre-assignment never hides it from anyone.
        foreach ([$branch['kurir'], $secondKurir] as $actor) {
            $list = $this->actingAs($actor)->getJson('/api/v1/kurir/orders');
            $list->assertOk();
            $this->assertTrue(collect($list->json('data'))->contains('id', $order->id));
        }

        // Once actually picked up (marked 'dikirim'), it becomes exclusive to whoever holds it.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $ownerList = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/orders');
        $ownerList->assertOk();
        $this->assertTrue(collect($ownerList->json('data'))->contains('id', $order->id));

        $otherList = $this->actingAs($secondKurir)->getJson('/api/v1/kurir/orders');
        $otherList->assertOk();
        $this->assertFalse(collect($otherList->json('data'))->contains('id', $order->id));
    }

    /**
     * A multi-product order has one shipment per product (Blueprint: "satu order bisa
     * beberapa kurir"). If ONE product is still 'diproses' (unclaimed) while ANOTHER has
     * already been picked up by a different kurir, the order-level match (used only to
     * decide whether the order appears at all) must not leak that other kurir's already-
     * claimed item into a sibling kurir's view of the same order.
     */
    public function test_a_pickedup_items_details_never_leak_to_another_kurir_via_a_shared_multi_item_order(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $productA = $this->makeProduct($branch['agen'], 'Kue A Diambil', 40000, 10);
        $productB = $this->makeProduct($branch['agen'], 'Kue B Belum Diambil', 30000, 10);

        $response = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        // First kurir picks up product A only — product B stays 'diproses', untouched.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$itemA->shipment_id}/status", ['status' => 'dikirim'])->assertOk();

        // Second kurir's queue: the order still appears (product B is still up for grabs),
        // but product A — already claimed by the first kurir — must not be in its items.
        $list = $this->actingAs($secondKurir)->getJson('/api/v1/kurir/orders');
        $list->assertOk();
        $seenOrder = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($seenOrder);

        $seenProductIds = collect($seenOrder['items'])->pluck('id');
        $this->assertTrue($seenProductIds->contains($itemB->id));
        $this->assertFalse($seenProductIds->contains($itemA->id));

        // The first kurir's own queue still shows both — their own claimed item, and the still-open one.
        $ownList = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/orders');
        $ownSeenOrder = collect($ownList->json('data'))->firstWhere('id', $order->id);
        $ownSeenProductIds = collect($ownSeenOrder['items'])->pluck('id');
        $this->assertTrue($ownSeenProductIds->contains($itemA->id));
        $this->assertTrue($ownSeenProductIds->contains($itemB->id));
    }

    /* ---------------------------------------------------------------
     * Additional authorization coverage (Blueprint §Kurir dashboard)
     * ------------------------------------------------------------- */

    public function test_kurir_cannot_verify_payment_or_change_cod_status(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Payment', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $this->actingAs($branch['kurir'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify")
            ->assertStatus(403);
        $this->actingAs($branch['kurir'])
            ->patchJson("/api/v1/orders/{$order->id}/payment/cod", ['status' => 'paid'])
            ->assertStatus(403);
    }

    public function test_kurir_cannot_update_shipment_status_for_an_order_belonging_to_another_agent(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($otherBranch['agen'], 'Kue Agen Lain', 40000, 10);
        $order = $this->placeOrder($otherBranch['konsumen'], $product, 1);

        $this->actingAs($branch['kurir'])
            ->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])
            ->assertStatus(403);
    }

    public function test_kurir_cannot_pickup_a_return_item_from_another_agents_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($otherBranch['agen'], 'Kue Retur Lain', 40000, 10);
        ['returnItemId' => $returnItemId] = $this->makeDeliveredAndReturnedItem($otherBranch, $product);

        $this->actingAs($branch['kurir'])
            ->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup")
            ->assertStatus(403);
    }

    public function test_delivered_report_includes_this_kurirs_own_fee_earned_per_order_never_another_kurirs(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Fee Kurir', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1500,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->actingAs($branch['kurir'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();

        $report = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/reports/delivered');
        $report->assertOk();
        $row = collect($report->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame(1500.0, (float) $row['fee_amount']);
        $this->assertArrayNotHasKey('agent_fee_amount', $row);
        $this->assertArrayNotHasKey('sales_fee_amount', $row);

        // A second kurir in the same branch, uninvolved in this delivery, never sees this order or its fee.
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id, 'name' => 'Kurir Lain']);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);
        $otherReport = $this->actingAs($secondKurir)->getJson('/api/v1/kurir/reports/delivered');
        $otherReport->assertOk();
        $this->assertFalse(collect($otherReport->json('data'))->contains('id', $order->id));
    }
}
