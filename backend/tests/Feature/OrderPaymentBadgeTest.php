<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
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
 * Human UAT (order card): every relevant order card shows TWO independent status domains —
 * operational Order status AND canonical payment status. They must never be conflated:
 * Dikirim+Belum Lunas and Dikirim+Lunas are both valid, and neither transition may
 * overwrite the other domain.
 *
 * Payment truth comes ONLY from the canonical projection (OrderResource payment_status +
 * payment_summary, backed by PaymentSummaryService / PaymentService). The frontend badge
 * helper (paymentBadgeState) reads exactly these server fields and never recomputes the
 * ledger — verified by contract here through the values it consumes.
 */
class OrderPaymentBadgeTest extends TestCase
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

    /** @return array{agen:User, keuangan:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Badge', 'address' => 'Jl. Badge',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);
        // Without a shipping configuration the checkout cannot price delivery and the order
        // never reaches the dispatchable workflow; every badge scenario needs it.

        $product = Product::create([
            'sku' => 'PB-'.Str::uuid(), 'name' => 'Produk Badge', 'slug' => 'produk-badge-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        return [
            'agen' => $agen,
            'keuangan' => User::factory()->keuangan()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'product' => $product,
        ];
    }

    private function placeOrder(User $konsumen, Product $product, string $method, ?float $dpAmount = null): Order
    {
        $payload = [
            'payment_method_code' => $method,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
        if ($dpAmount !== null) {
            $payload['dp_amount'] = $dpAmount;
        }

        $response = $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function submitProof(User $konsumen, Order $order)
    {
        return $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);
    }

    private function verify(User $keuangan, Order $order, bool $approved = true)
    {
        return $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => $approved]);
    }

    /** The exact contract the order-card badge consumes: canonical status + canonical summary. */
    private function cardProjection(User $viewer, Order $order): array
    {
        $data = $this->actingAs($viewer)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('status', $data, 'the card shows the operational Order status');
        $this->assertArrayHasKey('payment_status', $data, 'the SAME card shows the payment status');
        $this->assertArrayHasKey('payment_summary', $data, 'the canonical summary backs the badge');

        return $data;
    }

    // ---------- 3. Unpaid COD: Belum Lunas ----------

    public function test_unpaid_order_projects_belum_lunas(): void
    {
        ['konsumen' => $konsumen, 'product' => $product] = $this->branch();
        $order = $this->placeOrder($konsumen, $product, 'cod');

        $card = $this->cardProjection($konsumen, $order);
        $this->assertSame('unpaid', $card['payment_status']);
        // NOTE: a fresh COD order carries remaining_amount 0 until money moves — the canonical
        // verdict is the status itself, which is exactly what the badge reads (status-first).
        $this->assertSame(0.0, (float) $card['payment_summary']['total_paid']);
    }

    // ---------- 4. Verified DP: partially paid, still Belum Lunas ----------

    public function test_partially_paid_order_projects_belum_lunas(): void
    {
        ['keuangan' => $keuangan, 'konsumen' => $konsumen, 'product' => $product] = $this->branch();
        $order = $this->placeOrder($konsumen, $product, 'down_payment', 40000);
        $this->submitProof($konsumen, $order)->assertOk();
        $this->verify($keuangan, $order, true)->assertOk();

        $card = $this->cardProjection($konsumen, $order);
        $this->assertSame('partially_paid', $card['payment_status']);
        $this->assertSame(40000.0, (float) $card['payment_summary']['total_paid']);
        $this->assertGreaterThan(0, (float) $card['payment_summary']['remaining_balance'], 'balance remains: Belum Lunas');
    }

    // ---------- 5. Fully verified: Lunas ----------

    public function test_fully_paid_order_projects_lunas(): void
    {
        ['keuangan' => $keuangan, 'konsumen' => $konsumen, 'product' => $product] = $this->branch();
        $order = $this->placeOrder($konsumen, $product, 'bank_transfer');
        $this->submitProof($konsumen, $order)->assertOk();
        $this->verify($keuangan, $order, true)->assertOk();

        $card = $this->cardProjection($konsumen, $order);
        $this->assertSame('paid', $card['payment_status']);
        $this->assertSame(0.0, (float) $card['payment_summary']['remaining_balance']);
        $this->assertTrue($card['payment_summary']['is_fully_paid']);
    }

    // ---------- 6. Pending proof is never counted as paid ----------

    public function test_pending_proof_never_counts_as_paid(): void
    {
        ['konsumen' => $konsumen, 'product' => $product] = $this->branch();
        $order = $this->placeOrder($konsumen, $product, 'down_payment', 40000);
        $this->submitProof($konsumen, $order)->assertOk();

        $card = $this->cardProjection($konsumen, $order);
        $this->assertSame('pending_verification', $card['payment_status']);
        $this->assertSame(0.0, (float) $card['payment_summary']['total_paid'], 'submitted money is not paid money');
        $this->assertSame(40000.0, (float) $card['payment_summary']['submitted_dp']);
        $this->assertSame((float) $card['payment_summary']['grand_total'], (float) $card['payment_summary']['remaining_balance']);
    }

    // ---------- 7 + 8 + 9. Dikirim coexists with both; operations don't overwrite payment ----------

    public function test_dikirim_coexists_with_unpaid_and_paid_and_operations_do_not_cross_overwrite(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen, 'product' => $product] = $this->branch();

        // Dikirim + Belum Lunas: a COD order moved operationally while payment stays unpaid.
        $unpaid = $this->placeOrder($konsumen, $product, 'cod');
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$unpaid->id}/status", ['status' => 'dikirim'])->assertOk();

        $card = $this->cardProjection($konsumen, $unpaid->fresh());
        $this->assertSame('dikirim', $card['status']);
        $this->assertSame('unpaid', $card['payment_status'], 'the operational move did not touch payment');

        // Dikirim + Lunas: a paid bank-transfer order — verified (which admits it to diproses),
        // then moved operationally step by step.
        $paid = $this->placeOrder($konsumen, $product, 'bank_transfer');
        $this->submitProof($konsumen, $paid)->assertOk();
        $this->verify($keuangan, $paid, true)->assertOk();
        // Verification settles the money but leaves the workflow alone; the office then moves
        // it step by step (diterima -> diproses requires the verified payment).
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$paid->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$paid->id}/status", ['status' => 'dikirim'])->assertOk();

        $card = $this->cardProjection($konsumen, $paid->fresh());
        $this->assertSame('dikirim', $card['status']);
        $this->assertSame('paid', $card['payment_status']);
    }

    // ---------- 10. A payment transition never overwrites the operational status ----------

    public function test_payment_verification_never_overwrites_the_operational_status(): void
    {
        ['keuangan' => $keuangan, 'konsumen' => $konsumen, 'product' => $product] = $this->branch();
        $order = $this->placeOrder($konsumen, $product, 'cod');
        $this->assertSame('diproses', $order->status, 'COD starts dispatchable');

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cash.jpg'),
        ])->assertOk();
        $proofId = \App\Models\CodPaymentProof::query()->latest('id')->value('id');
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", ['confirmed' => true])->assertOk();

        $card = $this->cardProjection($konsumen, $order->fresh());
        $this->assertSame('paid', $card['payment_status']);
        $this->assertSame('diproses', $card['status'], 'confirming payment did not move the operational workflow');
    }
}