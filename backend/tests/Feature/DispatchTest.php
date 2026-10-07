<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * IMP-003 — the Koordinator-Kurir dispatch workspace.
 *
 * A dispatcher sees ONLY its own branch's dispatchable deliveries
 * (diproses + still unassigned) and can assign couriers; it never sees
 * financial projections (dispatch rows are operational), cannot progress
 * delivery status (that's the executor's job), never earns a fee, and the
 * self_sub / Gudang invariant holds: an order with a courier assigned (or
 * self-sub-owned) leaves the queue for good.
 */
class DispatchTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Dispatch', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'name' => 'Koord Kurir', 'phone' => '08123']);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);
        $superAdmin = User::factory()->superAdmin()->create();

        // A second branch for cross-agent assertions.
        $agen2 = User::factory()->agen()->create();
        $agen2->update(['agent_id' => $agen2->id]);
        AgentProfile::create([
            'user_id' => $agen2->id, 'store_name' => 'Toko Luar', 'address' => 'Jl. QA2',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $koordinator2 = User::factory()->koordinatorKurir()->create(['agent_id' => $agen2->id, 'name' => 'Koord Luar']);
        $kurir2 = User::factory()->kurir()->create(['agent_id' => $agen2->id, 'name' => 'Kurir Luar']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir2->id, 'agent_id' => $agen2->id, 'name' => $kurir2->name, 'is_active' => true]);

        return compact('agen', 'admin', 'konsumen', 'koordinator', 'kurir', 'superAdmin', 'agen2', 'koordinator2', 'kurir2');
    }

    private function makeProduct(User $agen, string $name, int $price): Product
    {
        $product = Product::create([
            'sku' => 'TEST-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        return $product;
    }

    private function placeOrder(User $konsumen, Product $product, int $qty = 1, ?string $villageId = null): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $villageId ?? $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function shipmentIdFor(Order $order): int
    {
        return Shipment::query()->where('order_id', $order->id)->value('id');
    }

    public function test_dispatch_queue_shows_only_unassigned_diproses_orders_of_own_branch(): void
    {
        $b = $this->makeBranch();
        $p1 = $this->makeProduct($b['agen'], 'Kue A', 30000);
        $p2 = $this->makeProduct($b['agen2'], 'Kue B', 30000);

        $myOrder = $this->placeOrder($b['konsumen'], $p1);
        $otherBranchOrder = $this->placeOrder(
            User::factory()->konsumen()->create(['agent_id' => $b['agen2']->id]),
            $p2, 1, $this->seedTestVillage()
        );

        // Assign a courier to my order — it must leave the queue.
        $kurirMyBranch = Courier::query()->where('agent_id', $b['agen']->id)->first();
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($myOrder).'/courier', [
            'courier_id' => $kurirMyBranch->id,
        ])->assertOk();

        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        // Only the OTHER branch's order could still be dispatchable, but cross-agent
        // isolation hides it — so the koordinator sees nothing.
        $this->assertCount(0, $data)
            ? $this->assertSame([], $data)
            : $this->assertNotContains($myOrder->id, array_column($data, 'order_id'));
        $this->assertNotContains($otherBranchOrder->id, array_column($data, 'order_id'), 'cross-branch order leaked');
    }

    public function test_koordinator_sees_unassigned_diproses_order_and_assigns_courier(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue C', 40000);
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($order->id, (int) $response->json('data.0.order_id'));
        $this->assertSame('diproses', $response->json('data.0.status'));
        $this->assertFalse($response->json('data.0.paid_in_full'));
        // Operational row: no financial amount fields at all.
        $this->assertArrayNotHasKey('total_amount', $response->json('data.0'));
        $this->assertArrayNotHasKey('grand_total', $response->json('data.0'));
        $this->assertArrayNotHasKey('remaining_amount', $response->json('data.0'));

        // Assign the courier as koordinator.
        $kurirMyBranch = Courier::query()->where('agent_id', $b['agen']->id)->first();
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirMyBranch->id,
        ])->assertOk();

        // Now the order leaves the dispatch queue.
        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_koordinator_cannot_progress_a_shipment_assigned_to_another_courier(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue D', 40000);
        $order = $this->placeOrder($b['konsumen'], $p);

        // A kurir claims the delivery first.
        $kurirMyBranch = Courier::query()->where('agent_id', $b['agen']->id)->first();
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/courier', [
            'courier_id' => $kurirMyBranch->id,
        ])->assertOk();

        // The koordinator must NOT be able to progress a delivery that belongs to a DIFFERENT
        // executor (A1-01: executor-only authority). Self-execution is covered by DispatchRemediationTest.
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$this->shipmentIdFor($order).'/status', [
            'status' => 'dikirim',
        ])->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'diproses']);
    }

    public function test_dispatch_is_cross_agent_isolated_and_koordinator_sees_only_own_branch(): void
    {
        $b = $this->makeBranch();
        $p1 = $this->makeProduct($b['agen'], 'Kue E', 30000);
        $p2 = $this->makeProduct($b['agen2'], 'Kue F', 30000);

        $myOrder = $this->placeOrder($b['konsumen'], $p1);
        $myOrder->update(['status' => 'dikirim']); // no longer dispatchable either
        $other = $this->placeOrder(
            User::factory()->konsumen()->create(['agent_id' => $b['agen2']->id]),
            $p2, 1, $this->seedTestVillage()
        );

        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));

        // The other branch's koordinator sees ITS own dispatchable order.
        $response2 = $this->actingAs($b['koordinator2'])->getJson('/api/v1/dispatch');
        $response2->assertOk();
        $this->assertCount(1, $response2->json('data'));
        $this->assertSame($other->id, (int) $response2->json('data.0.order_id'));
    }

    public function test_super_admin_dispatch_requires_agent_id_and_filters_by_delivery_date_region_paid(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue G', 40000);
        $village = $this->seedTestVillage();

        $order = $this->placeOrder($b['konsumen'], $p, 1, $village);
        $order->load('items');
        $order->items->first()->update(['requested_delivery_date' => now()->addDays(2)->toDateString()]);
        $order->update(['payment_status' => 'paid', 'paid_amount' => $order->total_amount, 'remaining_amount' => 0]);

        // super_admin without agent_id → 422.
        $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch')->assertStatus(422);

        // With agent_id + delivery_date + district + village + paid filters → visible.
        $district = DB::table('districts')->where('id', '999999')->value('id');
        $regency = DB::table('regencies')->where('id', '9999')->value('id');
        $response = $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch?agent_id='.$b['agen']->id
            .'&delivery_date='.now()->addDays(2)->toDateString()
            .'&district_id='.$district
            .'&village_id='.$village
            .'&paid=paid');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.paid_in_full'));

        // Same order with paid=unpaid → filtered out.
        $response = $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch?agent_id='.$b['agen']->id.'&paid=unpaid');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_koordinator_couriers_endpoint_returns_only_own_branch_active_couriers(): void
    {
        $b = $this->makeBranch();

        // Active courier in branch 1, active courier in branch 2.
        $kurirMyBranch = Courier::query()->where('agent_id', $b['agen']->id)->first();
        $kurirOtherBranch = Courier::query()->where('agent_id', $b['agen2']->id)->first();

        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/couriers');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains((int) $kurirMyBranch->id, $ids);
        $this->assertNotContains((int) $kurirOtherBranch->id, $ids);

        // Deactivate my branch courier — no longer assignable.
        $kurirMyBranch->update(['is_active' => false]);
        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/couriers');
        $this->assertNotContains((int) $kurirMyBranch->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_super_admin_dispatch_couriers_requires_a_valid_agent_and_scopes_correctly(): void
    {
        $b = $this->makeBranch();

        // Without agent_id → 422 (previously silently resolved to agent 0).
        $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch/couriers')->assertStatus(422);

        // Unknown agent → 422, never a 500.
        $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch/couriers?agent_id=999999')->assertStatus(422);

        // Valid agent → only that branch's couriers.
        $response = $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch/couriers?agent_id='.$b['agen']->id)->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertContains($b['kurir']->name, $names);
        $this->assertNotContains($b['kurir2']->name, $names);
    }

    public function test_koordinator_cannot_assign_cross_agent_courier(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue H', 30000);
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        $kurirOtherBranch = Courier::query()->where('agent_id', $b['agen2']->id)->first();

        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirOtherBranch->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('shipments', ['id' => $sid, 'courier_id' => null]);
    }

    public function test_koordinator_never_earns_a_fee_and_self_sub_never_enters_dispatch_queue(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue I', 30000);
        $this->actingAs($b['superAdmin'])->putJson("/api/v1/products/{$p->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2000,
        ])->assertOk();
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        // Deliver via the assigned kurir (as in CourierSystemTest).
        $kurirMyBranch = Courier::query()->where('agent_id', $b['agen']->id)->first();
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirMyBranch->id,
        ])->assertOk();

        $this->actingAs($b['kurir'])->patchJson('/api/v1/shipments/'.$sid.'/status', ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['kurir'])->patch('/api/v1/shipments/'.$sid.'/status', [
            'status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();

        // Only the kurir earned a fee — never the koordinator (no dispatcher fee).
        // The commission is keyed by order_item (see recordCommissionsForItems).
        $kurirFeeCommission = \App\Models\Commission::query()
            ->where('order_id', $order->id)
            ->where('beneficiary_role', 'courier')
            ->first();
        $this->assertNotNull($kurirFeeCommission, 'courier commission not recorded');
        $this->assertSame((int) $b['kurir']->id, (int) $kurirFeeCommission->beneficiary_user_id);
        $this->assertDatabaseMissing('commissions', [
            'order_id' => $order->id, 'beneficiary_role' => 'courier', 'beneficiary_user_id' => $b['koordinator']->id,
        ]);

        // self_sub order never enters dispatch queue: a self_sub shipment's
        // self_delivered_by_user_id is set at creation, so the whereDoesntHave
        // excludes it.
        $salesKurirSub = User::factory()->salesKurirSub()->create(['agent_id' => $b['agen']->id]);
        // Create a direct self_sub shipment on a fresh order (mirroring OrderService wiring).
        $order2 = $this->placeOrder($b['konsumen'], $this->makeProduct($b['agen'], 'Kue J', 20000));
        Shipment::query()->where('order_id', $order2->id)->update([
            'delivery_mode' => 'self_sub', 'self_delivered_by_user_id' => $salesKurirSub->id, 'courier_id' => null,
        ]);
        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'), 'self_sub shipment leaked into dispatch queue');
    }
}