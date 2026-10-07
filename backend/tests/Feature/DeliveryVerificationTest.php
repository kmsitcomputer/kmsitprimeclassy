<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\DeliveryVerification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / decision B: append-only Admin final delivery verification, separate from payment and from
 * the courier's own delivery action. Idempotent by Idempotency-Key.
 */
class DeliveryVerificationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->b = $this->branch();
    }

    private function branch(?int $agentId = null): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'DV-'.Str::uuid(), 'name' => 'Delivery Cake', 'slug' => 'dv-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        return compact('agen', 'admin', 'konsumen', 'product');
    }

    private function deliveredShipment(array $branch): Shipment
    {
        $id = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $branch['product']->id, 'quantity' => 2]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $shipment = OrderItem::where('order_id', $id)->firstOrFail()->shipment;
        // The courier's own delivery action already happened — this suite isolates Admin verification.
        // It is performed through the CANONICAL office delivery path (diproses -> dikirim -> terkirim,
        // which transitions every item) so the shipment's aggregate lifecycle is DERIVED by the
        // domain instead of being fabricated. Writing `status/delivered_at` straight onto the shipment
        // used to leave its item still in `diproses` — an impossible state that bypassed exactly the
        // invariant this suite is about (see ShipmentAggregateLifecycleTest).
        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$id}/status", ['status' => 'terkirim'])->assertOk();

        $shipment = $shipment->fresh();
        $this->assertSame('delivered', $shipment->status, 'precondition: the shipment really is delivered');

        return $shipment;
    }

    private function record(Shipment $shipment, User $actor, string $outcome, ?string $note = null, ?string $key = null)
    {
        // Always send an explicit header: the Laravel test client persists default headers across
        // requests, so omitting it would silently inherit an earlier Idempotency-Key.
        return $this->actingAs($actor)->withHeaders(['Idempotency-Key' => $key ?? ''])
            ->postJson("/api/v1/shipments/{$shipment->id}/delivery-verifications", ['outcome' => $outcome, 'note' => $note]);
    }

    public function test_admin_can_record_received_with_actor_and_timestamp(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        $this->record($shipment, $this->b['admin'], 'received', 'barang diterima baik', (string) Str::uuid())->assertCreated();

        $verification = DeliveryVerification::query()->firstOrFail();
        $this->assertSame('received', $verification->outcome);
        $this->assertSame($this->b['admin']->id, $verification->verified_by);
        $this->assertSame($shipment->id, $verification->shipment_id);
        $this->assertNotNull($verification->verified_at);
    }

    public function test_history_is_append_only_and_latest_wins(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        $this->record($shipment, $this->b['admin'], 'not_received', 'belum diterima', (string) Str::uuid())->assertCreated();
        $this->record($shipment, $this->b['admin'], 'received', 'akhirnya diterima', (string) Str::uuid())->assertCreated();

        $rows = DeliveryVerification::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('not_received', $rows[0]->outcome, 'the previous outcome is never erased');
        $this->assertSame('received', $rows[1]->outcome);
    }

    public function test_idempotency_key_prevents_duplicate_history(): void
    {
        $shipment = $this->deliveredShipment($this->b);
        $key = (string) Str::uuid();

        $first = $this->record($shipment, $this->b['admin'], 'received', null, $key)->assertCreated()->json('data.id');
        $second = $this->record($shipment, $this->b['admin'], 'received', null, $key)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DeliveryVerification::query()->count());
    }

    public function test_only_admin_and_super_admin_roles_reach_the_endpoint(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        foreach (['agen', 'konsumen'] as $role) {
            $this->actingAs($this->b[$role])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->postJson("/api/v1/shipments/{$shipment->id}/delivery-verifications", ['outcome' => 'received'])
                ->assertForbidden();
        }
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    public function test_admin_of_another_agent_cannot_verify(): void
    {
        $shipment = $this->deliveredShipment($this->b);
        $other = $this->branch();

        $this->record($shipment, $other['admin'], 'received')->assertForbidden();
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    public function test_cannot_verify_a_shipment_that_has_not_been_delivered(): void
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');
        $shipment = OrderItem::where('order_id', $id)->firstOrFail()->shipment;

        $this->record($shipment, $this->b['admin'], 'received', null, (string) Str::uuid())->assertUnprocessable();
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    public function test_invalid_outcome_is_rejected(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        $this->record($shipment, $this->b['admin'], 'maybe', null, (string) Str::uuid())->assertUnprocessable();
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    /* ---------------- MAJOR-3: no leak through the generic Order resource ---------------- */

    public function test_generic_order_detail_hides_delivery_verifications_from_non_admin(): void
    {
        $shipment = $this->deliveredShipment($this->b);
        $this->record($shipment, $this->b['admin'], 'received', 'internal admin note', (string) Str::uuid())->assertCreated();

        // Admin sees the history.
        $admin = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$shipment->order_id}")->assertOk();
        $this->assertNotEmpty($admin->json('data.delivery_verifications'));

        // The konsumen (order owner) must not — no verifier identity, no internal notes.
        $konsumen = $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$shipment->order_id}")->assertOk();
        $this->assertArrayNotHasKey('delivery_verifications', $konsumen->json('data'));
        $this->assertStringNotContainsString('internal admin note', $konsumen->getContent());
    }

    /* ---------------- MAJOR-4: idempotency hardening ---------------- */

    public function test_idempotency_key_is_required(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        $this->record($shipment, $this->b['admin'], 'received', null, null)->assertStatus(422);
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    public function test_oversized_idempotency_key_is_rejected(): void
    {
        $shipment = $this->deliveredShipment($this->b);

        $this->record($shipment, $this->b['admin'], 'received', null, str_repeat('x', 101))->assertStatus(422);
        $this->assertSame(0, DeliveryVerification::query()->count());
    }

    public function test_reused_key_for_a_different_shipment_is_a_conflict(): void
    {
        $first = $this->deliveredShipment($this->b);
        $second = $this->deliveredShipment($this->b);
        $key = (string) Str::uuid();

        $this->record($first, $this->b['admin'], 'received', null, $key)->assertCreated();
        $this->record($second, $this->b['admin'], 'received', null, $key)->assertStatus(409);

        $this->assertSame(1, DeliveryVerification::query()->count());
    }

    public function test_reused_key_for_a_different_outcome_is_a_conflict(): void
    {
        $shipment = $this->deliveredShipment($this->b);
        $key = (string) Str::uuid();

        $this->record($shipment, $this->b['admin'], 'received', null, $key)->assertCreated();
        $this->record($shipment, $this->b['admin'], 'not_received', null, $key)->assertStatus(409);

        $this->assertSame(1, DeliveryVerification::query()->count());
    }

    public function test_reused_key_for_a_different_note_is_a_conflict(): void
    {
        $shipment = $this->deliveredShipment($this->b);
        $key = (string) Str::uuid();

        $this->record($shipment, $this->b['admin'], 'received', 'first note', $key)->assertCreated();
        $this->record($shipment, $this->b['admin'], 'received', 'changed note', $key)->assertStatus(409);

        $this->assertSame(1, DeliveryVerification::query()->count());
    }
}
