<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * SEC-COURIER-001 (Owner decision 2026-11-08): ordinary Kurir are ASSIGNMENT-ONLY; the single
 * self-pickup exception is a Sales-Kurir-Sub (SKS) delivering a Sub-sourced order THEY placed
 * (orders.placed_by_user_id). Returns are assigned by office/dispatch, never self-claimed.
 */
class CourierAssignmentIsolationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;
    use \Tests\Support\AcceptsWarehouseDemand;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $kurirA = $this->kurir($agen);
        $kurirB = $this->kurir($agen);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $subA = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $subB = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $locA = $this->location($agen, $subA, 'LA');
        $locB = $this->location($agen, $subB, 'LB');

        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $sub = Product::create(['sku' => 'SUB-'.Str::uuid(), 'name' => 'Kue Sub', 'slug' => 'kue-sub-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $sub->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $sub->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $sub->id, 'stock_type' => 'sub', 'sub_location_id' => $locA->id, 'quantity' => 10]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $sub->id, 'stock_type' => 'sub', 'sub_location_id' => $locB->id, 'quantity' => 10]);
        $std = Product::create(['sku' => 'STD-'.Str::uuid(), 'name' => 'Kue Std', 'slug' => 'kue-std-'.uniqid(), 'has_variations' => false, 'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $std->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        return compact('agen', 'admin', 'kurirA', 'kurirB', 'konsumen', 'subA', 'subB', 'sub', 'std');
    }

    private function kurir(User $agen): User
    {
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        return $kurir;
    }

    private function location(User $agen, User $owner, string $code): WarehouseSubLocation
    {
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => $code, 'name' => $code, 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $owner->id])->save();

        return $location;
    }

    private function payload(Product $product, array $extra = []): array
    {
        return [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ] + $extra;
    }

    private function standardShipment(): Shipment
    {
        $id = $this->actingAs($this->b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($this->b['std'], ['konsumen_id' => $this->b['konsumen']->id]))
            ->assertCreated()->json('data.id');

        return Shipment::query()->where('order_id', $id)->firstOrFail();
    }

    private function subOrderBy(User $sks): Order
    {
        $id = $this->actingAs($sks)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($this->b['sub'], ['stock_source' => 'sub']))
            ->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function assign(Shipment $shipment, User $kurir): void
    {
        $courierId = Courier::where('user_id', $kurir->id)->value('id');
        $this->actingAsDispatcher($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $courierId])->assertOk();
    }

    private function listedOrderIds(User $actor): array
    {
        return collect($this->actingAs($actor)->getJson('/api/v1/kurir/orders')->assertOk()->json('data'))->pluck('id')->all();
    }

    /* ----------------------------- ordinary Kurir ----------------------------- */

    public function test_kurir_sees_only_own_assignments_and_never_unassigned_or_other_couriers_shipments(): void
    {
        $unassigned = $this->standardShipment();
        $forA = $this->standardShipment();
        $forB = $this->standardShipment();
        $this->assign($forA, $this->b['kurirA']);
        $this->assign($forB, $this->b['kurirB']);

        $a = $this->listedOrderIds($this->b['kurirA']);
        $this->assertContains($forA->order_id, $a);
        $this->assertNotContains($forB->order_id, $a);
        $this->assertNotContains($unassigned->order_id, $a);

        $bIds = $this->listedOrderIds($this->b['kurirB']);
        $this->assertContains($forB->order_id, $bIds);
        $this->assertNotContains($forA->order_id, $bIds);
        $this->assertNotContains($unassigned->order_id, $bIds);

        // Status-filtered discovery obeys the same rule.
        foreach (['diproses', 'dikirim', 'terkirim'] as $status) {
            $rows = collect($this->actingAs($this->b['kurirB'])->getJson("/api/v1/kurir/orders?status={$status}")->assertOk()->json('data'))->pluck('id');
            $this->assertFalse($rows->contains($forA->order_id));
            $this->assertFalse($rows->contains($unassigned->order_id));
        }
    }

    public function test_kurir_cannot_claim_unassigned_or_mutate_another_couriers_shipment_by_direct_api(): void
    {
        $unassigned = $this->standardShipment();
        $forA = $this->standardShipment();
        $this->assign($forA, $this->b['kurirA']);

        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/shipments/{$unassigned->id}/status", ['status' => 'dikirim'])->assertStatus(403);
        $this->actingAs($this->b['kurirB'])->patchJson("/api/v1/shipments/{$forA->id}/status", ['status' => 'dikirim'])->assertStatus(403);

        $this->assertNull($unassigned->fresh()->courier_id);
        $this->assertSame('pending', $unassigned->fresh()->status);
        $this->assertSame('pending', $forA->fresh()->status);
    }

    public function test_assigned_kurir_progresses_with_proof_and_loses_access_when_assignment_is_revoked(): void
    {
        $shipment = $this->standardShipment();
        $this->assign($shipment, $this->b['kurirA']);

        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        // Delivery proof is still mandatory.
        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim'])->assertStatus(422);
        $this->actingAs($this->b['kurirA'])->patch("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('p.jpg')])->assertOk();

        // Revocation (simulated at the data layer — there is no un-assign endpoint): access disappears.
        $other = $this->standardShipment();
        $this->assign($other, $this->b['kurirA']);
        $this->assertContains($other->order_id, $this->listedOrderIds($this->b['kurirA']));
        DB::table('shipments')->where('id', $other->id)->update(['courier_id' => null]);
        $this->assertNotContains($other->order_id, $this->listedOrderIds($this->b['kurirA']));
        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/shipments/{$other->id}/status", ['status' => 'dikirim'])->assertStatus(403);
    }

    public function test_kurir_cannot_reach_financial_files_or_generic_order_detail(): void
    {
        $shipment = $this->standardShipment();
        $this->assign($shipment, $this->b['kurirA']);

        $this->actingAs($this->b['kurirA'])->get("/api/v1/orders/{$shipment->order_id}/invoice")->assertStatus(403);
        $this->actingAs($this->b['kurirA'])->getJson("/api/v1/orders/{$shipment->order_id}")->assertStatus(403);
    }

    /* ----------------------------------- SKS ----------------------------------- */

    public function test_sks_self_pickup_is_allowed_only_for_the_order_they_created(): void
    {
        $order = $this->subOrderBy($this->b['subA']);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($this->b['subA']->id, (int) $order->placed_by_user_id);

        // SKS B (same branch) is not the creator/owner.
        $this->actingAs($this->b['subB'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertStatus(403);
        // SKS A is.
        $this->actingAs($this->b['subA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('in_transit', $shipment->fresh()->status);
    }

    public function test_sks_cannot_self_pickup_when_another_role_created_the_order_or_creator_is_unknown(): void
    {
        $order = $this->subOrderBy($this->b['subA']);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();

        // Created by an Agen (another role) — owner of the sub stock is still SKS A: must be refused.
        DB::table('orders')->where('id', $order->id)->update(['placed_by_user_id' => $this->b['agen']->id]);
        $this->actingAs($this->b['subA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertStatus(403);
        $this->assertSame('pending', $shipment->fresh()->status);

        // Legacy row without a recorded creator: fail closed (never inferred from branch/stock location).
        DB::table('orders')->where('id', $order->id)->update(['placed_by_user_id' => null]);
        $this->actingAs($this->b['subA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertStatus(403);
        $this->assertNotContains($order->id, $this->listedOrderIds($this->b['subA']));
    }

    public function test_sks_from_another_branch_cannot_pick_up_even_with_matching_ids(): void
    {
        $order = $this->subOrderBy($this->b['subA']);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();

        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $foreign = User::factory()->salesKurirSub()->create(['agent_id' => $otherAgen->id]);

        $status = $this->actingAs($foreign)->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    public function test_repeated_sks_pickup_never_double_consumes_or_changes_ownership(): void
    {
        $order = $this->subOrderBy($this->b['subA']);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->b['subA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($this->b['subB'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertStatus(403);
        $this->assertSame($this->b['subA']->id, (int) $shipment->fresh()->self_delivered_by_user_id);
        $this->assertNull($shipment->fresh()->courier_id);
    }

    /* ----------------------------------- Returns ----------------------------------- */

    public function test_return_assignment_requires_an_authorized_actor_and_an_eligible_same_branch_courier(): void
    {
        $shipment = $this->standardShipment();
        $this->assign($shipment, $this->b['kurirA']);
        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($this->b['kurirA'])->patch("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('p.jpg')])->assertOk();

        $item = $shipment->orderItems()->firstOrFail();
        $this->actingAs($this->b['konsumen'])->post("/api/v1/orders/{$shipment->order_id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'restock' => true]],
            'reason' => 'Rusak', 'evidence' => UploadedFile::fake()->image('e.jpg'),
        ])->assertCreated();
        $returnItemId = \App\Models\ReturnItem::where('order_item_id', $item->id)->value('id');
        $courierB = Courier::where('user_id', $this->b['kurirB']->id)->value('id');

        // A kurir cannot assign (route is office/dispatch only) nor claim.
        $this->actingAs($this->b['kurirB'])->patchJson("/api/v1/return-items/{$returnItemId}/courier", ['courier_id' => $courierB])->assertStatus(403);
        $this->actingAs($this->b['kurirB'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [])->assertStatus(403);

        // A foreign-branch courier is never an eligible assignee.
        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $foreignCourier = Courier::where('user_id', $this->kurir($otherAgen)->id)->value('id');
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/return-items/{$returnItemId}/courier", ['courier_id' => $foreignCourier])->assertStatus(422);

        // Authorized assignment works and is the only path to processing.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/return-items/{$returnItemId}/courier", ['courier_id' => $courierB])->assertOk();
        $this->actingAs($this->b['kurirB'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [])->assertOk();
        $this->actingAs($this->b['kurirA'])->patchJson("/api/v1/kurir/returns/{$returnItemId}/pickup", [])->assertStatus(403);
    }
}
