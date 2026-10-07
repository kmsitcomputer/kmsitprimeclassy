<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Codex Audit #1 — Group A durable regressions.
 *
 * A1-01  Koordinator self-executor (assign to self → becomes the actual courier,
 *        progresses pickup/proof/completion, earns exactly one courier fee; no
 *        dispatcher fee; cannot operate another courier's delivery).
 * A1-02  Koordinator → Kurir provisioning (same-Agent, parent=koordinator;
 *        denies forge/cross-agent/escalation).
 * A1-03  Assignment never overwrites an existing executor; terminal work rejected.
 * A1-05  Sibling shipments stay independently dispatchable (one-Shipment-per-item).
 *
 * A1-04 (Gudang/assignment race) is covered by the two-connection
 * DispatChRace concurrency test in DispatchAssignmentConcurrencyTest.
 */
class DispatchRemediationTest extends TestCase
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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko Remediation', 'address' => 'Jl. QA', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'name' => 'Koord Remed', 'phone' => '08123']);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Remed']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);
        $superAdmin = User::factory()->superAdmin()->create();

        $agen2 = User::factory()->agen()->create();
        $agen2->update(['agent_id' => $agen2->id]);
        AgentProfile::create(['user_id' => $agen2->id, 'store_name' => 'Toko Luar B', 'address' => 'Jl. QA2', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $koordinator2 = User::factory()->koordinatorKurir()->create(['agent_id' => $agen2->id, 'name' => 'Koord Luar B']);
        $kurir2 = User::factory()->kurir()->create(['agent_id' => $agen2->id, 'name' => 'Kurir Luar B']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir2->id, 'agent_id' => $agen2->id, 'name' => $kurir2->name, 'is_active' => true]);

        return compact('agen', 'admin', 'konsumen', 'koordinator', 'kurir', 'superAdmin', 'agen2', 'koordinator2', 'kurir2');
    }

    private function makeProduct(User $agen, string $name, int $price): Product
    {
        $product = Product::create([
            'sku' => 'REM-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
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

    public function test_koordinator_self_assignment_becomes_executor_with_pickup_proof_completion_and_exactly_one_fee(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue Self', 30000);
        $this->actingAs($b['superAdmin'])->putJson("/api/v1/products/{$p->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2000,
        ])->assertOk();
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        // Self-assign via the koordinator executor sentinel (courier_id = 0).
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => 0,
        ])->assertOk();

        // The koordinator's own Courier profile was created (lazily).
        $profile = Courier::query()->where('user_id', $b['koordinator']->id)->first();
        $this->assertNotNull($profile, 'koordinator self-executor Courier profile not created');
        $this->assertSame((int) $b['agen']->id, (int) $profile->agent_id);
        $this->assertDatabaseHas('shipments', ['id' => $sid, 'courier_id' => $profile->id]);

        // The shipment appears in the koordinator's own delivery queue (Pengiriman Saya).
        $queue = $this->actingAs($b['koordinator'])->getJson('/api/v1/kurir/orders');
        $queue->assertOk();
        $this->assertContains($order->id, array_map('intval', array_column($queue->json('data'), 'id')));

        $this->actingAs($b['koordinator'])->getJson('/api/v1/shipments/'.$sid.'/receipt')->assertOk();
        $this->actingAs($b['koordinator2'])->getJson('/api/v1/shipments/'.$sid.'/receipt')->assertForbidden();

        // The koordinator progresses it EXACTLY like a kurir.
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/status', [
            'status' => 'dikirim',
        ])->assertOk();

        $this->actingAs($b['koordinator'])->patch('/api/v1/shipments/'.$sid.'/status', [
            'status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();

        $this->actingAs($b['koordinator'])->getJson('/api/v1/shipments/'.$sid.'/receipt')->assertOk();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/kurir/orders?status=terkirim')->assertOk()->assertJsonFragment(['id' => $order->id]);

        // Exactly ONE courier commission, credited to the koordinator — never a dispatcher fee.
        $commission = Commission::query()
            ->where('order_id', $order->id)
            ->where('beneficiary_role', 'courier')
            ->get();
        $this->assertCount(1, $commission, 'expected exactly one courier fee for the self-executor');
        $this->assertSame((int) $b['koordinator']->id, (int) $commission->first()->beneficiary_user_id);
    }

    public function test_koordinator_can_manage_own_child_courier_but_not_other_accounts(): void
    {
        $b = $this->makeBranch();
        $child = User::factory()->kurir()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['koordinator']->id]);
        $this->actingAs($b['koordinator'])->getJson('/api/v1/users')->assertOk()->assertJsonFragment(['id' => $child->id]);
        $this->actingAs($b['koordinator'])->getJson("/api/v1/users/{$child->id}")->assertOk();
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/users/{$child->id}", ['name' => 'Managed courier'])->assertOk();
        foreach ([$b['kurir'], $b['agen'], $b['admin'], $b['kurir2']] as $other) {
            $this->actingAs($b['koordinator'])->getJson("/api/v1/users/{$other->id}")->assertForbidden();
            $this->actingAs($b['koordinator'])->patchJson("/api/v1/users/{$other->id}", ['status' => 'inactive'])->assertForbidden();
        }
        $this->actingAs($b['koordinator'])->deleteJson("/api/v1/users/{$child->id}")->assertForbidden();
    }

    public function test_koordinator_cannot_operate_another_couriers_delivery(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue Lain', 30000);
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        // A normal kurir is assigned by the dispatcher (not self).
        $kurirProfile = Courier::query()->where('user_id', $b['kurir']->id)->first();
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirProfile->id,
        ])->assertOk();

        // The koordinator cannot operate it — it is not their own assignment.
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/status', [
            'status' => 'dikirim',
        ])->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'diproses']);
    }

    public function test_koordinator_can_create_kurir_only_in_own_branch_and_cannot_escalate_or_forge(): void
    {
        $b = $this->makeBranch();

        // Koordinator creates a kurir — parent = the koordinator, agent = its branch.
        $response = $this->actingAs($b['koordinator'])->postJson('/api/v1/users', [
            'role' => 'kurir',
            'name' => 'Anak Koord', 'email' => 'anak.koord@example.com',
            'phone' => '0813001', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]);
        $response->assertStatus(201);

        $created = User::withoutGlobalScopes()->where('email', 'anak.koord@example.com')->firstOrFail();
        $this->assertSame((int) $b['agen']->id, (int) $created->agent_id);
        $this->assertSame((int) $b['koordinator']->id, (int) $created->parent_id);
        $this->assertNotNull($created->courierProfile, 'koordinator-created kurir needs a Courier profile');

        // Cannot create anything except kurir.
        $this->actingAs($b['koordinator'])->postJson('/api/v1/users', [
            'role' => 'admin', 'name' => 'Escalate', 'email' => 'esc@example.com',
            'phone' => '0813002', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertStatus(403);

        // Cannot forge agent_id (cross-agent).
        $this->actingAs($b['koordinator'])->postJson('/api/v1/users', [
            'role' => 'kurir', 'name' => 'Forge K', 'email' => 'forge.k@example.com',
            'phone' => '0813003', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'agent_id' => $b['agen2']->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'forge.k@example.com']);
    }

    public function test_assignment_never_overwrites_an_existing_executor_and_rejects_terminal_work(): void
    {
        $b = $this->makeBranch();
        $p = $this->makeProduct($b['agen'], 'Kue Assign', 30000);
        $order = $this->placeOrder($b['konsumen'], $p);
        $sid = $this->shipmentIdFor($order);

        $kurirProfile = Courier::query()->where('user_id', $b['kurir']->id)->first();
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirProfile->id,
        ])->assertOk();

        // A different courier (second branch kurir is cross-agent → 422; use a second branch's
        // koordinator attempting cross-agent is already covered). Instead: a same-branch DIFFERENT
        // executor cannot be forced onto an already-claimed shipment.
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $b['agen']->id, 'name' => 'Kurir Kedua']);
        $secondProfile = Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $b['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $secondProfile->id,
        ])->assertStatus(422);
        $this->assertDatabaseHas('shipments', ['id' => $sid, 'courier_id' => $kurirProfile->id]);

        // Same courier re-assignment is an idempotent replay (still 200).
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirProfile->id,
        ])->assertOk();
        $this->assertDatabaseHas('shipments', ['id' => $sid, 'courier_id' => $kurirProfile->id]);

        // Terminal work: cancel the order, then try to assign → refused.
        $order->update(['status' => 'dibatalkan']);
        $this->actingAs($b['agen'])->patchJson('/api/v1/shipments/'.$sid.'/courier', [
            'courier_id' => $kurirProfile->id,
        ])->assertStatus(422);
        $this->assertDatabaseHas('shipments', ['id' => $sid, 'courier_id' => $kurirProfile->id]);
    }

    public function test_non_pending_shipments_cannot_be_assigned_or_listed_even_if_order_is_diproses(): void
    {
        $b = $this->makeBranch();
        $order = $this->placeOrder($b['konsumen'], $this->makeProduct($b['agen'], 'Failed Delivery', 30000));
        $shipment = $order->shipments()->firstOrFail();
        $courier = $b['kurir']->courierProfile;
        foreach (['failed', 'picked_up', 'in_transit', 'delivered'] as $status) {
            $shipment->update(['status' => $status]);
            $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $courier->id])->assertStatus(422);
            $this->getJson('/api/v1/dispatch')->assertOk()->assertJsonCount(0, 'data');
            $this->assertNull($shipment->fresh()->courier_id);
        }
    }

    public function test_inactive_accounts_and_sub_owner_profiles_are_not_standard_delivery_executors(): void
    {
        $b = $this->makeBranch();
        $order = $this->placeOrder($b['konsumen'], $this->makeProduct($b['agen'], 'Executor eligibility', 30000));
        $shipment = $order->shipments()->firstOrFail();
        $b['kurir']->update(['status' => 'inactive']);
        $profile = $b['kurir']->courierProfile;
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $profile->id])->assertStatus(422);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $b['agen']->id]);
        $subProfile = Courier::create(['agent_id' => $b['agen']->id, 'user_id' => $sub->id, 'name' => 'Sub executor', 'type' => 'internal', 'is_active' => true]);
        $this->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $subProfile->id])->assertStatus(422);
        $this->getJson('/api/v1/dispatch/couriers')->assertOk()->assertJsonMissing(['id' => $profile->id])->assertJsonMissing(['id' => $subProfile->id]);
        $this->assertNull($shipment->fresh()->courier_id);
    }

    public function test_sibling_shipments_stay_independently_dispatchable(): void
    {
        $b = $this->makeBranch();
        $p1 = $this->makeProduct($b['agen'], 'Kue A1', 30000);
        $p2 = $this->makeProduct($b['agen'], 'Kue B1', 30000);
        $order = $this->placeOrder($b['konsumen'], $p1);
        $order->items()->create([
            'product_id' => $p2->id, 'product_name_snapshot' => $p2->name, 'sku_snapshot' => $p2->sku,
            'unit_price_snapshot' => 30000, 'original_quantity' => 1, 'fulfilled_quantity' => 1,
            'subtotal_snapshot' => 30000, 'status' => 'diproses',
            'shipment_id' => $this->createSiblingShipment($order),
        ]);
        $order->refresh();

        $shipmentIds = Shipment::query()->where('order_id', $order->id)->orderBy('id')->pluck('id');

        // The dispatch queue shows BOTH unassigned shipments.
        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data, 'both sibling shipments should be dispatchable');
        // Dispatch orders by ID desc (newest first).
        $returnedIds = array_map('intval', array_column($data, 'shipment_id'));
        sort($returnedIds);
        $this->assertSame([(int) $shipmentIds[0], (int) $shipmentIds[1]], $returnedIds);

        // Assign the FIRST shipment — the sibling must REMAIN dispatchable.
        $kurirProfile = Courier::query()->where('user_id', $b['kurir']->id)->first();
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$shipmentIds[0].'/courier', [
            'courier_id' => $kurirProfile->id,
        ])->assertOk();

        $response = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data, 'the unassigned sibling must stay dispatchable');
        $this->assertSame((int) $shipmentIds[1], (int) $data[0]['shipment_id']);
    }

    private function createSiblingShipment(Order $order): int
    {
        $shipment = Shipment::create([
            'order_id' => $order->id, 'status' => 'pending',
        ]);

        return $shipment->id;
    }
}