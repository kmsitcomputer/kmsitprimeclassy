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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * COURIER-002 / COURIER-003: Koordinator-Kurir gets a branch-wide, read-only, operational-only
 * history of DELIVERED shipments (GET /api/v1/dispatch/history) and can open the delivered order
 * through the existing read-only order detail — without any money exposure or new mutations.
 */
class CoordinatorDeliveredHistoryTest extends TestCase
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

    private function branch(string $label): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => "Toko {$label}", 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => "Kurir {$label}"]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $product = Product::create(['sku' => 'H-'.Str::uuid(), 'name' => 'Kue H', 'slug' => 'kue-h-'.uniqid(), 'has_variations' => false, 'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        $superAdmin = User::factory()->superAdmin()->create();

        return compact('agen', 'admin', 'koordinator', 'kurir', 'konsumen', 'product', 'superAdmin');
    }

    private function shipment(array $b): Shipment
    {
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id, 'payment_method_code' => 'cod',
            'items' => [['product_id' => $b['product']->id, 'quantity' => 1]],
            'recipient_name' => 'Penerima', 'recipient_phone' => '0811', 'address_line' => 'Jl. Rahasia 1',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');

        return Shipment::query()->where('order_id', $id)->firstOrFail();
    }

    private function deliver(array $b, Shipment $shipment): void
    {
        $courierId = Courier::where('user_id', $b['kurir']->id)->value('id');
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $courierId])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('p.jpg')])->assertOk();
    }

    public function test_coordinator_sees_every_delivered_shipment_of_their_branch_with_the_real_executor(): void
    {
        $b = $this->branch('A');
        $delivered = $this->shipment($b);
        $this->deliver($b, $delivered);
        $inProgress = $this->shipment($b); // still pending: must be excluded

        $res = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/history')->assertOk();
        $ids = collect($res->json('data'))->pluck('shipment_id');

        $this->assertTrue($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($inProgress->id));
        $row = collect($res->json('data'))->firstWhere('shipment_id', $delivered->id);
        $this->assertSame('courier', $row['executor']['type']);
        $this->assertSame('Kurir A', $row['executor']['name']);
        $this->assertNotNull($row['delivered_at']);
        $this->assertTrue($row['proof_available']);
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_history_is_branch_isolated_and_exposes_no_financial_or_contact_fields(): void
    {
        $a = $this->branch('A');
        $b = $this->branch('B');
        $mine = $this->shipment($a);
        $this->deliver($a, $mine);
        $theirs = $this->shipment($b);
        $this->deliver($b, $theirs);

        $res = $this->actingAs($a['koordinator'])->getJson('/api/v1/dispatch/history')->assertOk();
        $this->assertSame([$mine->id], collect($res->json('data'))->pluck('shipment_id')->all());

        $json = json_encode($res->json('data'));
        foreach (['total_amount', 'paid_amount', 'dp_amount', 'remaining_amount', 'refund', 'commission', 'recipient_phone', 'address_line', 'Jl. Rahasia 1', 'payment'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "history must not expose {$leak}");
        }
    }

    public function test_history_filters_by_courier_date_and_paginates_stably(): void
    {
        $b = $this->branch('A');
        $other = User::factory()->kurir()->create(['agent_id' => $b['agen']->id, 'name' => 'Kurir Lain']);
        Courier::create(['type' => 'internal', 'user_id' => $other->id, 'agent_id' => $b['agen']->id, 'name' => $other->name, 'is_active' => true]);

        $s1 = $this->shipment($b);
        $this->deliver($b, $s1);
        $s2 = $this->shipment($b);
        $this->deliver($b, $s2);

        $courierId = Courier::where('user_id', $b['kurir']->id)->value('id');
        $otherId = Courier::where('user_id', $other->id)->value('id');
        $this->assertSame(2, $this->actingAs($b['koordinator'])->getJson("/api/v1/dispatch/history?courier_id={$courierId}")->json('meta.total'));
        $this->assertSame(0, $this->actingAs($b['koordinator'])->getJson("/api/v1/dispatch/history?courier_id={$otherId}")->json('meta.total'));

        $future = now()->addDays(3)->toDateString();
        $this->assertSame(0, $this->actingAs($b['koordinator'])->getJson("/api/v1/dispatch/history?delivered_from={$future}")->json('meta.total'));
        $today = now('Asia/Jakarta')->toDateString();
        $this->assertSame(2, $this->actingAs($b['koordinator'])->getJson("/api/v1/dispatch/history?delivered_from={$today}&delivered_to={$today}")->json('meta.total'));

        $p1 = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/history?per_page=1&page=1')->json('data.0.shipment_id');
        $p2 = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/history?per_page=1&page=2')->json('data.0.shipment_id');
        $this->assertNotSame($p1, $p2);
    }

    public function test_sks_self_delivery_is_attributed_to_the_sks_not_to_a_courier(): void
    {
        $b = $this->branch('A');
        $shipment = $this->shipment($b);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $b['agen']->id, 'name' => 'SKS Anton']);
        // Represent a completed valid SKS self-delivery directly at the data layer.
        $shipment->forceFill([
            'delivery_mode' => Shipment::DELIVERY_MODE_SELF_SUB, 'self_delivered_by_user_id' => $sks->id,
            'status' => 'delivered', 'delivered_at' => now(),
        ])->save();

        $row = collect($this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/history')->assertOk()->json('data'))->firstWhere('shipment_id', $shipment->id);
        $this->assertSame('sks', $row['executor']['type']);
        $this->assertSame('SKS Anton', $row['executor']['name']);
    }

    public function test_unprovable_executor_is_reported_as_unavailable_never_invented(): void
    {
        $b = $this->branch('A');
        $shipment = $this->shipment($b);
        $shipment->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

        $row = collect($this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/history')->assertOk()->json('data'))->firstWhere('shipment_id', $shipment->id);
        $this->assertSame('unavailable', $row['executor']['type']);
        $this->assertNull($row['executor']['name']);
    }

    public function test_super_admin_must_name_a_valid_branch_and_other_roles_are_refused(): void
    {
        $b = $this->branch('A');
        $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch/history')->assertStatus(422);
        $this->actingAs($b['superAdmin'])->getJson('/api/v1/dispatch/history?agent_id='.$b['agen']->id)->assertOk();
        $this->actingAs($b['kurir'])->getJson('/api/v1/dispatch/history')->assertStatus(403);
        $this->actingAs($b['konsumen'])->getJson('/api/v1/dispatch/history')->assertStatus(403);
    }

    public function test_coordinator_can_open_a_delivered_order_read_only_without_order_wide_mutations_or_invoice(): void
    {
        $b = $this->branch('A');
        $shipment = $this->shipment($b);
        $this->deliver($b, $shipment);
        $orderId = $shipment->order_id;

        $this->actingAs($b['koordinator'])->getJson("/api/v1/orders/{$orderId}")->assertOk();
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'dibatalkan'])->assertStatus(403);
        $this->actingAs($b['koordinator'])->get("/api/v1/orders/{$orderId}/invoice")->assertStatus(403);
        $this->assertNotSame('dibatalkan', Order::withoutGlobalScopes()->find($orderId)->status);
    }
}
