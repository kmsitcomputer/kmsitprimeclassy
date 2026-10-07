<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\ShippingConfiguration;
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
 * HUMAN UAT REMEDIATION UAT-004..UAT-008 — focused regression.
 *
 * Reuses only existing canonical capabilities (no new subsystem):
 * - UAT-004: pending submitted DP ("DP Diajukan") is exposed through the
 *   existing payment summary / finance report WITHOUT counting as paid.
 * - UAT-005: Gudang queue + Dispatch rows expose canonical item / quantity /
 *   requested delivery date per shipment.
 * - UAT-006: Koordinator "Ambil Pengiriman" uses the existing courier_id: 0
 *   self-assignment sentinel; conflicts fail safely.
 * - UAT-007: Koordinator reaches its own deliveries through the existing
 *   /kurir/orders own-delivery queue (no duplicate dashboard).
 * - UAT-008: in-scope Sales/Korsal verify via the canonical
 *   PaymentService::verifyBankTransfer; cross-scope is denied; audit actor
 *   is the actual verifier.
 */
class Uat4008RemediationTest extends TestCase
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

    private function branch(string $label = 'A'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko '.$label, 'address' => 'Jl. '.$label,
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'parent_id' => $sales->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);

        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'keuangan', 'admin', 'gudang', 'korsal', 'sales', 'konsumen', 'koordinator');
    }

    private function placeOrder(User $konsumen, User $agen, string $method = 'bank_transfer', ?float $dpAmount = null, ?string $deliveryDate = null): Order
    {
        $product = Product::create([
            'sku' => 'T-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $payload = [
            'payment_method_code' => $method,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
        if ($dpAmount !== null) {
            $payload['dp_amount'] = $dpAmount;
        }
        if ($deliveryDate !== null) {
            $payload['delivery_date'] = $deliveryDate;
        }

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        return Order::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function toDiproses(Order $order, User $office): void
    {
        $this->actingAs($office)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
    }

    // ---------- UAT-004 ----------

    public function test_pending_dp_is_exposed_as_diajukan_without_counting_as_paid(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'down_payment', 50000);

        // Before any proof: nothing submitted, nothing paid.
        $detail = $this->actingAs($keuangan)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(0.0, (float) $detail['payment_summary']['submitted_dp']);
        $this->assertNull($detail['payment_summary']['pending_verification_status']);

        // Konsumen submits the DP proof.
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('dp.jpg'),
        ])->assertOk();

        // UAT-004 example: Grand Total billed, DP Diajukan 50000 pending,
        // DP Dibayar 0, Total Dibayar 0, Sisa = full total.
        $detail = $this->actingAs($keuangan)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(50000.0, (float) $detail['payment_summary']['submitted_dp']);
        $this->assertSame('pending', $detail['payment_summary']['pending_verification_status']);
        $this->assertTrue($detail['payment_summary']['has_pending_proof']);
        $this->assertSame(0.0, (float) $detail['payment_summary']['verified_dp']);
        $this->assertSame(0.0, (float) $detail['payment_summary']['total_paid']);
        $this->assertSame((float) $detail['payment_summary']['grand_total'], (float) $detail['payment_summary']['remaining_balance']);

        // Finance work surface exposes the same triage row.
        $rows = $this->actingAs($keuangan)->getJson('/api/v1/reports/finance-orders')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('order_no', $order->order_no);
        $this->assertNotNull($row, 'finance-orders must contain the DP order');
        $this->assertSame(50000.0, (float) $row['dp_submitted']);
        $this->assertSame('pending', $row['verification_status']);
        $this->assertTrue($row['has_pending_proof']);
        $this->assertSame(0.0, (float) $row['dp_paid']);
        $this->assertSame(0.0, (float) $row['total_paid']);
        $this->assertNotEmpty($row['customer']);
    }

    public function test_dp_approval_moves_canonical_paid_totals_exactly_once(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'down_payment', 50000);
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('dp.jpg'),
        ])->assertOk();

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $detail = $this->actingAs($keuangan)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(50000.0, (float) $detail['payment_summary']['verified_dp']);
        $this->assertSame(50000.0, (float) $detail['payment_summary']['total_paid']);
        $this->assertSame(0.0, (float) $detail['payment_summary']['submitted_dp']);
        $this->assertNull($detail['payment_summary']['pending_verification_status']);

        // Replay / double approval is refused — never double-counted.
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertStatus(422);
        $this->assertSame(50000.0, (float) $order->fresh()->paid_amount);
    }

    public function test_rejected_dp_never_counts_as_paid(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'down_payment', 50000);
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('dp.jpg'),
        ])->assertOk();

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", [
            'approved' => false, 'rejection_reason' => 'bukti buram',
        ])->assertOk();

        $detail = $this->actingAs($keuangan)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(0.0, (float) $detail['payment_summary']['total_paid']);
        $this->assertSame(0.0, (float) $detail['payment_summary']['submitted_dp']);
    }

    // ---------- UAT-005 ----------

    public function test_gudang_queue_and_dispatch_expose_item_quantity_and_delivery_date(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'gudang' => $gudang, 'konsumen' => $konsumen, 'koordinator' => $koordinator] = $this->branch();
        // COD skips the payment-verification gate (canonical OrderService rule) — dispatch eligibility is about status/courier, not payment.
        // An explicit delivery date proves dates stay distinguishable per item/shipment.
        $order = $this->placeOrder($konsumen, $agen, 'cod', null, '2026-11-01');
        $this->assertSame('diproses', $order->fresh()->status, 'COD orders start in the warehouse/dispatch queue');

        // Gudang sees the real demand, not just a count.
        $queue = $this->actingAs($gudang)->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->json('data');
        $row = collect($queue)->firstWhere('order_no', $order->order_no);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['items']);
        foreach ($row['items'] as $item) {
            $this->assertNotEmpty($item['product_name']);
            $this->assertGreaterThan(0, (int) $item['fulfilled_quantity']);
            $this->assertNotEmpty($item['requested_delivery_date'], 'each gudang item must carry its delivery date');
        }

        // Dispatch exposes one card per canonical shipment with its items.
        $dispatch = $this->actingAs($koordinator)->getJson('/api/v1/dispatch')->assertOk()->json('data');
        $this->assertNotEmpty($dispatch);
        foreach ($dispatch as $card) {
            $this->assertNotNull($card['shipment_id']);
            $this->assertNotEmpty($card['delivery_date']);
            $this->assertNotEmpty($card['items'], 'each dispatch card must list its shipment items');
            foreach ($card['items'] as $item) {
                $this->assertArrayHasKey('quantity', $item);
                $this->assertArrayHasKey('requested_delivery_date', $item);
            }
        }
    }

    // ---------- UAT-006 + UAT-007 ----------

    public function test_koordinator_self_assign_leaves_dispatch_and_reaches_own_queue(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen, 'koordinator' => $koordinator] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'cod');
        $this->assertSame('diproses', $order->fresh()->status);

        $shipmentId = Shipment::query()->withoutGlobalScopes()->where('order_id', $order->id)->value('id');
        $this->assertNotNull($shipmentId);

        // UAT-006: explicit self-assignment through the existing sentinel contract.
        $this->actingAs($koordinator)->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => 0])->assertOk();

        $courierId = Shipment::query()->withoutGlobalScopes()->whereKey($shipmentId)->value('courier_id');
        $this->assertNotNull($courierId);
        $this->assertSame($koordinator->id, Courier::query()->whereKey($courierId)->value('user_id'));

        // The shipment leaves the unassigned dispatch queue…
        $dispatch = $this->actingAs($koordinator)->getJson('/api/v1/dispatch')->assertOk()->json('data');
        $this->assertEmpty(collect($dispatch)->where('shipment_id', $shipmentId));

        // …and appears in the coordinator's own delivery queue (UAT-007: existing queue reused).
        $own = $this->actingAs($koordinator)->getJson('/api/v1/kurir/orders')->assertOk()->json('data');
        $this->assertNotEmpty(collect($own)->where('id', $order->id));

        // The coordinator progresses its OWN shipment…
        $this->actingAs($koordinator)->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        // …but never another executor's shipment.
        $other = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $this->actingAs($other)->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'terkirim'])->assertStatus(403);
    }

    public function test_conflicting_assignment_is_rejected_safely(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen, 'koordinator' => $koordinator] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'cod');
        $this->assertSame('diproses', $order->fresh()->status);

        $shipmentId = Shipment::query()->withoutGlobalScopes()->where('order_id', $order->id)->value('id');
        $this->actingAs($koordinator)->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => 0])->assertOk();

        // A second coordinator claiming the same shipment fails safely (no overwrite, no 500).
        $other = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $this->actingAs($other)->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => 0])->assertStatus(422);

        // Idempotent replay by the SAME executor stays 200.
        $this->actingAs($koordinator)->patchJson("/api/v1/shipments/{$shipmentId}/courier", ['courier_id' => 0])->assertOk();
    }

    // ---------- UAT-008 (superseded: Sales/Korsal are NOT payment approvers) ----------

    public function test_sales_and_korsal_cannot_verify_settle_or_confirm_even_in_scope(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'korsal' => $korsal, 'sales' => $sales, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'down_payment', 50000);
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('dp.jpg'),
        ])->assertOk();

        foreach ([$sales, $korsal] as $actor) {
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertForbidden();
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertForbidden();
        }
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount, 'no money moved');

        // Keuangan path stays valid, including pelunasan request.
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame(50000.0, (float) $order->fresh()->paid_amount);
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();
    }

    public function test_cross_agent_sales_and_korsal_cannot_verify(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'down_payment', 50000);
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('dp.jpg'),
        ])->assertOk();

        $b = $this->branch('B');
        $this->assertContains($this->actingAs($b['sales'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->status(), [403, 404]);
        $this->assertContains($this->actingAs($b['korsal'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->status(), [403, 404]);
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
    }
}
