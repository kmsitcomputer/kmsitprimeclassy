<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\Stock\SubStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * CR-SALES-KURIR-SUB-DELIVERY-001: a Sales-Kurir-Sub may deliver standard
 * (Agent-sourced) shipments as an explicitly assigned courier — only for their
 * own purchase / own sales snapshot / current referral scope, same branch,
 * assigned by an authorized office role. Self-assignment stays forbidden, the
 * self_sub path is untouched, stock moves once, and the courier fee is never
 * merged with the sales fee.
 */
class SalesKurirSubStandardDeliveryTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

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
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        $sub1 = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $sub1->id, 'agent_id' => $agen->id, 'name' => $sub1->name, 'is_active' => true]);
        $sub2 = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $sub2->id, 'agent_id' => $agen->id, 'name' => $sub2->name, 'is_active' => true]);
        // Legacy profile-less Sales-Kurir-Sub (role renamed in place, C3).
        $subNoProfile = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);

        $konsumen1 = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sub1->id]);
        $konsumen2 = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sub2->id]);
        $konsumenPlain = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $location1 = $this->location($agen, $sub1, 'SKS1');

        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'SKS-'.Str::uuid(), 'name' => 'Kue SKS', 'slug' => 'kue-sks-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location1->id, 'quantity' => 10]);

        // Non-zero fees so commission attribution is observable (courier fee →
        // recorded once per item on delivery; sales fee stays a separate row).
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 2000,
        ])->assertOk();

        // Second branch for cross-agent rejection.
        $agen2 = User::factory()->agen()->create();
        $agen2->update(['agent_id' => $agen2->id]);
        AgentProfile::create(['user_id' => $agen2->id, 'store_name' => 'T2', 'address' => 'Jl. T2', 'latitude' => -6.2, 'longitude' => 106.8]);
        $subOther = User::factory()->salesKurirSub()->create(['agent_id' => $agen2->id]);
        Courier::create(['type' => 'internal', 'user_id' => $subOther->id, 'agent_id' => $agen2->id, 'name' => $subOther->name, 'is_active' => true]);

        return compact('agen', 'admin', 'gudang', 'kurir', 'sub1', 'sub2', 'subNoProfile', 'konsumen1', 'konsumen2', 'konsumenPlain', 'location1', 'product', 'agen2', 'subOther');
    }

    private function location(User $agen, User $owner, string $code): WarehouseSubLocation
    {
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => $code, 'name' => $code, 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $owner->id])->save();

        return $location;
    }

    private function payload(int $qty, array $extra = []): array
    {
        return [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ] + $extra;
    }

    private function placeOrder(User $actor, int $qty, array $extra = []): Order
    {
        $id = $this->actingAs($actor)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($qty, $extra))
            ->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function subCourier(User $sub): Courier
    {
        return Courier::where('user_id', $sub->id)->firstOrFail();
    }

    private function assign(User $actor, int $shipmentId, int $courierId)
    {
        return $this->actingAs($actor)->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => $courierId]);
    }

    /* ---------- the four ownership × stock-source combinations ---------- */

    public function test_own_purchase_from_sub_stock_stays_self_sub_owner_flow(): void
    {
        $order = $this->placeOrder($this->b['sub1'], 2, ['stock_source' => 'sub']);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assertSame('self_sub', $shipment->delivery_mode);
        $this->assertSame($this->b['sub1']->id, $shipment->self_delivered_by_user_id);

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub1']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub1'], UploadedFile::fake()->image('proof.jpg'));

        $this->assertSame(8, app(SubStockService::class)->physical($this->b['location1']->id, $this->b['product']->id, null));
        $this->assertSame('consumed', SubStockReservation::query()->firstOrFail()->status);
    }

    public function test_own_purchase_from_agent_stock_is_assignable_to_self_and_deliverable(): void
    {
        // Self-purchase from Agent stock → standard shipment, no executor yet.
        $order = $this->placeOrder($this->b['sub1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $this->assertSame('standard', $shipment->delivery_mode);
        $this->assertNull($shipment->courier_id);

        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();

        // Visible in the assignee's courier queue (and not the sibling's).
        $ids = fn (User $actor) => collect($this->actingAs($actor)->getJson('/api/v1/kurir/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids($this->b['sub1'])->contains($order->id));
        $this->assertFalse($ids($this->b['sub2'])->contains($order->id));

        // Deliver with proof through the shipment endpoint as the assignee.
        $this->actingAs($this->b['sub1'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($this->b['sub1'])
            ->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])
            ->assertOk();

        $this->assertSame('terkirim', $order->items()->firstOrFail()->fresh()->status);
    }

    public function test_referral_order_from_sub_stock_stays_self_sub_owner_flow(): void
    {
        // Sales-Kurir-Sub places an order FOR its referred konsumen from Sub stock.
        $order = $this->placeOrder($this->b['sub1'], 2, ['konsumen_id' => $this->b['konsumen1']->id, 'stock_source' => 'sub']);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assertSame('self_sub', $shipment->delivery_mode);
        $this->assertSame($this->b['sub1']->id, $shipment->self_delivered_by_user_id);

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub1']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub1'], UploadedFile::fake()->image('proof.jpg'));

        $this->assertSame(8, app(SubStockService::class)->physical($this->b['location1']->id, $this->b['product']->id, null));
    }

    public function test_referral_consumer_agent_order_is_assignable_to_their_sales_kurir_sub(): void
    {
        // Referred konsumen checks out themselves → Agent stock, snapshot sales_id = sub1.
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $this->assertSame($this->b['sub1']->id, $order->sales_id);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();

        app(CourierService::class)->updateOrderItemStatus(
            $order->items()->firstOrFail(), 'dikirim', $this->b['sub1']
        );
        app(CourierService::class)->updateOrderItemStatus(
            $order->items()->firstOrFail()->fresh(), 'terkirim', $this->b['sub1'], UploadedFile::fake()->image('proof.jpg')
        );

        $this->assertSame('terkirim', $order->items()->firstOrFail()->fresh()->status);
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    /* ---------- rejections ---------- */

    public function test_another_subs_referral_consumer_is_rejected(): void
    {
        $order = $this->placeOrder($this->b['konsumen2'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertStatus(422);

        // …while the actual referrer is accepted.
        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub2'])->id)->assertOk();
    }

    public function test_unrelated_consumer_without_referral_link_is_rejected(): void
    {
        $order = $this->placeOrder($this->b['konsumenPlain'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertStatus(422);
    }

    public function test_different_agent_sub_is_rejected(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['subOther'])->id)->assertStatus(422);

        // The cross-branch candidate is also absent from the eligible list.
        $courierIds = collect($this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertOk()->json('data'))->pluck('courier_id');
        $this->assertFalse($courierIds->contains($this->subCourier($this->b['subOther'])->id));
    }

    public function test_inactive_sub_or_profile_is_rejected(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $courierId = $this->subCourier($this->b['sub1'])->id;

        $this->b['sub1']->update(['status' => 'inactive']);
        $this->assign($this->b['admin'], $shipment->id, $courierId)->assertStatus(422);
        $this->b['sub1']->update(['status' => 'active']);

        Courier::whereKey($courierId)->update(['is_active' => false]);
        $this->assign($this->b['admin'], $shipment->id, $courierId)->assertStatus(422);
    }

    public function test_sales_kurir_sub_cannot_assign_itself(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        // Route role gate: sales-kurir-sub is not an assigning role.
        $this->assign($this->b['sub1'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertForbidden();
        $this->assertNull($shipment->fresh()->courier_id);

        // Nor may it claim the unassigned shipment through the pickup path.
        $this->actingAs($this->b['sub1'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertForbidden();
        $this->assertNull($shipment->fresh()->courier_id);
    }

    public function test_cross_branch_office_assignment_is_refused(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $admin2 = User::factory()->admin()->create(['agent_id' => $this->b['agen2']->id]);

        $this->assign($admin2, $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertForbidden();
        $this->assertNull($shipment->fresh()->courier_id);
    }

    /* ---------- assignment integrity, proof, stock, commission ---------- */

    public function test_assigned_shipment_cannot_be_taken_and_replay_is_idempotent(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $subCourierId = $this->subCourier($this->b['sub1'])->id;
        $kurirCourierId = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail()->id;

        $this->assign($this->b['admin'], $shipment->id, $subCourierId)->assertOk();

        // Another courier cannot take it — neither operate nor reassign.
        $this->actingAs($this->b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertForbidden();
        $this->assign($this->b['admin'], $shipment->id, $kurirCourierId)->assertStatus(422);

        // The sibling Sub cannot operate it either.
        $this->actingAs($this->b['sub2'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertForbidden();

        // Same-executor replay is idempotent.
        $this->assign($this->b['admin'], $shipment->id, $subCourierId)->assertOk();
        $this->assertSame($subCourierId, $shipment->fresh()->courier_id);
    }

    public function test_delivery_proof_is_required_for_assigned_standard_shipment(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub1']);

        $this->expectException(\App\Exceptions\ApiException::class);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub1']);
    }

    public function test_assignment_moves_no_stock_and_commission_is_written_once(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 2);
        $item = $order->items()->firstOrFail();
        $shipment = $item->shipment;
        $courierId = $this->subCourier($this->b['sub1'])->id;

        $onHandBefore = ProductStock::where('agent_id', $this->b['agen']->id)->where('product_id', $this->b['product']->id)->value('quantity_on_hand');

        $this->assign($this->b['admin'], $shipment->id, $courierId)->assertOk();

        // Assignment is not a stock movement.
        $this->assertSame((int) $onHandBefore, (int) ProductStock::where('agent_id', $this->b['agen']->id)->where('product_id', $this->b['product']->id)->value('quantity_on_hand'));

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub1']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub1'], UploadedFile::fake()->image('proof.jpg'));

        $courierRows = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->get();
        $this->assertCount(1, $courierRows);
        $this->assertSame($this->b['sub1']->id, $courierRows->first()->beneficiary_user_id);

        // Sales commission is a separate row, never merged into the courier fee.
        $salesRows = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'sales')->get();
        $this->assertCount(1, $salesRows);
        $this->assertNotSame($courierRows->first()->id, $salesRows->first()->id);
    }

    /* ---------- eligible-couriers endpoint ---------- */

    public function test_eligible_couriers_lists_only_shipment_eligible_candidates(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $couriersBefore = Courier::count();

        $rows = collect($this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertOk()->json('data'));

        // READ-ONLY: listing never mints Courier rows.
        $this->assertSame($couriersBefore, Courier::count());

        $kurirCourierId = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail()->id;
        $rowByUser = $rows->keyBy('user_id');

        $this->assertTrue($rows->pluck('courier_id')->contains($kurirCourierId), 'branch kurir stays assignable');
        $this->assertSame($kurirCourierId, $rowByUser[$this->b['kurir']->id]['courier_id']);
        $this->assertTrue($rows->pluck('courier_id')->contains($this->subCourier($this->b['sub1'])->id), 'eligible Sales-Kurir-Sub listed');
        $this->assertFalse($rows->pluck('user_id')->contains($this->b['sub2']->id), 'ineligible sibling never listed');

        // A self_sub shipment offers no assignable candidate at all.
        $subOrder = $this->placeOrder($this->b['sub1'], 1, ['stock_source' => 'sub']);
        $subShipment = $subOrder->items()->firstOrFail()->shipment;
        $this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$subShipment->id}/eligible-couriers")->assertOk()->assertJsonCount(0, 'data');

        // An already-assigned shipment lists nobody.
        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();
        $this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertOk()->assertJsonCount(0, 'data');

        // The endpoint itself is office-gated.
        $this->actingAs($this->b['sub1'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertForbidden();
    }

    public function test_profile_less_legacy_sub_is_listed_without_mutation_and_resolved_on_assign(): void
    {
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first());

        // subNoProfile is not in anyone's referral scope yet → not listed.
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $userIds = collect($this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertOk()->json('data'))->pluck('user_id');
        $this->assertFalse($userIds->contains($this->b['subNoProfile']->id));
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first());

        // After an audited referral move into their scope, listing shows them with
        // courier_id = null — and STILL creates nothing.
        $this->b['konsumen1']->update(['sales_id' => $this->b['subNoProfile']->id]);
        $rows = collect($this->actingAs($this->b['admin'])->getJson("/api/v1/shipments/{$shipment->id}/eligible-couriers")->assertOk()->json('data'));
        $row = $rows->firstWhere('user_id', $this->b['subNoProfile']->id);
        $this->assertNotNull($row, 'C3 candidate listed');
        $this->assertNull($row['courier_id']);
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first(), 'GET performs no write');

        // …and the office assigns them by user id; the profile is created on this write path.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [
            'executor_user_id' => $this->b['subNoProfile']->id,
        ])->assertOk();
        $profileId = Courier::where('user_id', $this->b['subNoProfile']->id)->value('id');
        $this->assertNotNull($profileId, 'C3 profile created on assignment');
        $this->assertSame((int) $profileId, (int) $shipment->fresh()->courier_id);
    }

    public function test_resolve_rejects_ineligible_or_ambiguous_targets(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $courierId = $this->subCourier($this->b['sub1'])->id;

        // Ineligible Sales-Kurir-Sub by user id.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [
            'executor_user_id' => $this->b['sub2']->id,
        ])->assertStatus(422);
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first(), 'refused resolve mints nothing');

        // Non-SKS user id.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [
            'executor_user_id' => $this->b['kurir']->id,
        ])->assertStatus(422);

        // Unknown user id.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [
            'executor_user_id' => 999999,
        ])->assertStatus(422);

        // Both identifiers at once.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [
            'courier_id' => $courierId, 'executor_user_id' => $this->b['sub1']->id,
        ])->assertStatus(422);

        // Neither identifier.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", [])->assertStatus(422);

        $this->assertNull($shipment->fresh()->courier_id);
    }

    public function test_resolve_writes_nothing_when_the_assignment_could_never_complete(): void
    {
        // Profile-less, in-scope candidate — but the shipment is NOT assignable.
        $this->b['konsumen1']->update(['sales_id' => $this->b['subNoProfile']->id]);

        // (a) already assigned to another courier (the branch kurir — always assignable)
        $assigned = $this->placeOrder($this->b['konsumen1'], 1);
        $assignedShipment = $assigned->items()->firstOrFail()->shipment;
        $kurirCourierId = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail()->id;
        $this->assign($this->b['admin'], $assignedShipment->id, $kurirCourierId)->assertOk();
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$assignedShipment->id}/courier", [
            'executor_user_id' => $this->b['subNoProfile']->id,
        ])->assertStatus(422);
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first());

        // (b) order no longer in the fulfillment window
        $terminal = $this->placeOrder($this->b['konsumen1'], 1);
        $terminalShipment = $terminal->items()->firstOrFail()->shipment;
        $terminal->update(['status' => 'terkirim']);
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$terminalShipment->id}/courier", [
            'executor_user_id' => $this->b['subNoProfile']->id,
        ])->assertStatus(422);
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first());

        // (c) self_sub shipment (never assignable, courier ban stays DB-enforced)
        $selfSub = $this->placeOrder($this->b['sub1'], 1, ['stock_source' => 'sub']);
        $selfSubShipment = $selfSub->items()->firstOrFail()->shipment;
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$selfSubShipment->id}/courier", [
            'executor_user_id' => $this->b['subNoProfile']->id,
        ])->assertStatus(422);
        $this->assertNull($selfSubShipment->fresh()->courier_id);
        $this->assertNull(Courier::where('user_id', $this->b['subNoProfile']->id)->first());
    }

    /* ---------- BLOCKER-1: assigned standard items are visible (only) to the assignee ---------- */

    public function test_assigned_standard_items_are_visible_only_to_the_assignee(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $item = $order->items()->firstOrFail();
        $shipment = $item->shipment;
        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();

        $rowFor = fn (User $actor) => collect(
            $this->actingAs($actor)->getJson('/api/v1/kurir/orders')->assertOk()->json('data')
        )->firstWhere('id', $order->id);

        $own = $rowFor($this->b['sub1']);
        $this->assertNotNull($own, 'assignee sees the order');
        $this->assertCount(1, $own['items'], 'assignee sees exactly the assigned items');
        $this->assertSame($item->id, $own['items'][0]['id']);
        $this->assertSame($shipment->id, $own['items'][0]['shipment_id']);

        $this->assertNull($rowFor($this->b['sub2']), 'sibling Sub sees nothing');
    }

    public function test_split_shipments_keep_each_executors_items_isolated(): void
    {
        $product2 = Product::create(['sku' => 'SKS2-'.Str::uuid(), 'name' => 'Kue SKS 2', 'slug' => 'kue-sks-2-'.uniqid(), 'has_variations' => false, 'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $this->b['agen']->id, 'product_id' => $product2->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $this->b['agen']->id, 'product_id' => $product2->id, 'stock_type' => 'transit', 'quantity' => 50]);

        $order = $this->placeOrder($this->b['konsumen1'], 1);

        // SC-03 adds a second line on another date → its own canonical shipment.
        $this->actingAs($this->b['admin'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'product_id' => $product2->id, 'quantity' => 1,
                'requested_delivery_date' => now()->addDays(5)->toDateString(),
                'reason' => 'review split',
            ])->assertCreated();

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertNotSame($items[0]->shipment_id, $items[1]->shipment_id);

        // SC-03-first preserves Gudang access: fulfil the added demand (propose +
        // approve) before any courier assignment may proceed.
        $requestId = \App\Models\StockRequest::where('order_id', $order->id)->value('id');
        $lines = $items->map(fn ($it) => [
            'item_id' => \App\Models\StockRequestItem::where('stock_request_id', $requestId)->where('order_item_id', $it->id)->value('id'),
            'quantity' => $it->fulfilled_quantity,
        ])->all();
        $proposalId = $this->actingAs($this->b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$requestId}/proposals", ['items' => $lines])->assertCreated()->json('data.id');
        $this->actingAs($this->b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposalId}/approve")->assertOk();

        $this->assign($this->b['admin'], $items[0]->shipment_id, $this->subCourier($this->b['sub1'])->id)->assertOk();
        $kurirCourierId = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail()->id;
        $this->assign($this->b['admin'], $items[1]->shipment_id, $kurirCourierId)->assertOk();

        // Each executor picks up their own shipment: from then on the work is exclusive.
        app(CourierService::class)->updateOrderItemStatus($items[0]->fresh(), 'dikirim', $this->b['sub1']);
        app(CourierService::class)->updateOrderItemStatus($items[1]->fresh(), 'dikirim', $this->b['kurir']);

        $itemsFor = fn (User $actor) => collect(
            $this->actingAs($actor)->getJson('/api/v1/kurir/orders')->assertOk()->json('data')
        )->firstWhere('id', $order->id)['items'] ?? null;

        $subItems = $itemsFor($this->b['sub1']);
        $this->assertCount(1, $subItems);
        $this->assertSame($items[0]->id, $subItems[0]['id']);

        $kurirItems = $itemsFor($this->b['kurir']);
        $this->assertCount(1, $kurirItems);
        $this->assertSame($items[1]->id, $kurirItems[0]['id']);
    }

    /* ---------- MEDIUM-5: a deactivated executor cannot continue ---------- */

    public function test_deactivated_assignee_cannot_continue_delivery(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $this->assign($this->b['admin'], $shipment->id, $this->subCourier($this->b['sub1'])->id)->assertOk();

        $this->b['sub1']->update(['status' => 'inactive']);

        // Service path refuses.
        try {
            app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub1']->fresh());
            $this->fail('deactivated executor must be refused');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(403, $e->status());
        }

        // HTTP path refuses as well.
        $this->actingAs($this->b['sub1'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertForbidden();

        $this->assertSame('diproses', $order->items()->firstOrFail()->fresh()->status);
    }

    public function test_deactivated_kurir_cannot_continue_delivery(): void
    {
        $order = $this->placeOrder($this->b['konsumen1'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $kurirCourierId = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail()->id;
        $this->assign($this->b['admin'], $shipment->id, $kurirCourierId)->assertOk();

        $this->b['kurir']->update(['status' => 'inactive']);

        $this->actingAs($this->b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertForbidden();
        $this->assertSame('diproses', $order->items()->firstOrFail()->fresh()->status);
    }
}
