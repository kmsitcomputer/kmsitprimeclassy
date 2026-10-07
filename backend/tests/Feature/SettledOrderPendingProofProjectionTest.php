<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\CodPaymentProof;
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
 * UAT-004 projection integrity: "DP Diajukan / Menunggu Verifikasi" must describe money that is
 * genuinely still awaiting verification.
 *
 * A DP order has several manual transactions (the DP itself, then its settlement). The projection
 * used to read "the newest PENDING verification of ANY of them", so a verification row left behind
 * on a SUPERSEDED transaction kept reporting submitted_dp / pending on an order that was already
 * LUNAS — the exact readout the Human asked for, stating something false. The projection is now
 * scoped to the order's CURRENT manual transaction, the same row the verification action applies to
 * (PaymentController::latestManualTransaction).
 */
class SettledOrderPendingProofProjectionTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    /** @var array{agen:User, keuangan:User, konsumen:User, product:Product} */
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
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Lunas', 'address' => 'Jl. Lunas',
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
        $product = Product::create([
            'sku' => 'SL-'.Str::uuid(), 'name' => 'Produk Lunas', 'slug' => 'produk-lunas-'.uniqid(),
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

    private function placeDpOrder(): Order
    {
        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'down_payment', 'dp_amount' => 40000,
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function submitProof(Order $order)
    {
        return $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('proof.jpg')]);
    }

    private function summary(Order $order): array
    {
        return $this->actingAs($this->b['konsumen'])
            ->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.payment_summary');
    }

    private function financeRow(Order $order): array
    {
        $rows = collect($this->actingAs($this->b['keuangan'])->getJson('/api/v1/reports/finance-orders')->json('data'))
            ->filter(fn ($r) => (int) $r['order_id'] === $order->id)->values();

        $this->assertCount(1, $rows, 'the finance report carries exactly one row for this order');

        return $rows->first();
    }

    public function test_a_superseded_pending_dp_proof_never_reports_pending_on_a_settled_order(): void
    {
        $order = $this->placeDpOrder();

        // The DP proof is submitted first, then a settlement is requested, then the settlement proof
        // is uploaded and verified. The DP's own verification row is superseded but still pending.
        $this->submitProof($order)->assertOk();
        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();
        $this->submitProof($order)->assertOk();
        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status, 'precondition: the order is LUNAS');
        $this->assertSame(0.0, (float) $fresh->remaining_amount);

        $summary = $this->summary($fresh);
        $this->assertNull($summary['pending_verification_status'], 'a settled order is not awaiting verification');
        $this->assertFalse($summary['has_pending_proof']);
        $this->assertSame(0.0, (float) $summary['submitted_dp'], 'no submitted money is outstanding on a settled order');
        $this->assertTrue($summary['is_fully_paid']);

        $row = $this->financeRow($fresh);
        $this->assertNull($row['verification_status'], 'Finance sees no phantom pending verification');
        $this->assertFalse($row['has_pending_proof']);
        $this->assertSame(0.0, (float) $row['dp_submitted']);
        $this->assertSame('paid', $row['payment_status']);
    }

    public function test_a_genuinely_pending_dp_still_reports_submitted_and_pending(): void
    {
        // The fix must not silence a real pending submission.
        $order = $this->placeDpOrder();
        $this->submitProof($order)->assertOk();

        $summary = $this->summary($order);
        $this->assertSame('pending', $summary['pending_verification_status']);
        $this->assertTrue($summary['has_pending_proof']);
        $this->assertSame(40000.0, (float) $summary['submitted_dp']);
        $this->assertSame(0.0, (float) $summary['total_paid'], 'pending money is never paid money');
        $this->assertSame('pending_verification', $summary['payment_status']);

        $row = $this->financeRow($order);
        $this->assertSame('pending', $row['verification_status']);
        $this->assertSame(40000.0, (float) $row['dp_submitted']);
    }

    public function test_partially_paid_order_after_settlement_still_shows_no_phantom_pending(): void
    {
        $order = $this->placeDpOrder();

        // Verify the DP itself first, then settle the remainder: the DP proof is no longer pending.
        $this->submitProof($order)->assertOk();
        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();
        $this->submitProof($order)->assertOk();
        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $summary = $this->summary($fresh);
        $this->assertNull($summary['pending_verification_status']);
        $this->assertSame(0.0, (float) $summary['submitted_dp']);
        $this->assertSame(40000.0, (float) $summary['verified_dp'], 'the verified DP tranche is still reported');
    }

    public function test_a_confirmed_cod_proof_does_not_leave_the_order_awaiting_confirmation(): void
    {
        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/cod-proof", ['proof' => UploadedFile::fake()->image('cash.jpg')])
            ->assertOk();

        // Genuinely pending: still awaiting confirmation.
        $pending = $this->summary($order);
        $this->assertSame('pending', $pending['pending_verification_status']);

        $proofId = CodPaymentProof::query()->latest('id')->value('id');
        $this->actingAs($this->b['keuangan'])
            ->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", ['confirmed' => true])
            ->assertOk();

        $confirmed = $this->summary($order->fresh());
        $this->assertNull($confirmed['pending_verification_status'], 'a confirmed COD proof is no longer awaiting confirmation');
        $this->assertFalse($confirmed['has_pending_proof']);
        $this->assertSame('paid', $confirmed['payment_status']);
    }
}
