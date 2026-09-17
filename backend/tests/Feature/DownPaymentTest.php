<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * DOWN PAYMENT (DP): a partial, proof-based payment that must never be
 * confused with PAID/LUNAS, plus its later pelunasan (settlement) flow.
 */
class DownPaymentTest extends TestCase
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
            'user_id' => $agen->id, 'store_name' => 'Toko DP', 'address' => 'Jl. DP',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        // DP reuses the branch's bank_transfer destination account.
        $bankTransfer = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);

        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'admin', 'keuangan', 'konsumen');
    }

    private function makeProduct(User $agen, int $price = 1000000, int $stockQty = 10): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Kue DP', 'slug' => 'kue-dp-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    private function destination(): array
    {
        return [
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
    }

    private function placeDpOrder(User $konsumen, Product $product, mixed $dpAmount, bool $includeDp = true): TestResponse
    {
        $payload = [
            'payment_method_code' => 'down_payment',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ];

        if ($includeDp) {
            $payload['dp_amount'] = $dpAmount;
        }

        return $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
    }

    private function submitProof(User $konsumen, int $orderId): TestResponse
    {
        return $this->actingAs($konsumen)->postJson("/api/v1/orders/{$orderId}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);
    }

    public function test_dp_checkout_requires_a_nominal_strictly_between_zero_and_the_total(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 1000000);

        // Missing
        $this->placeDpOrder($konsumen, $product, null, includeDp: false)->assertStatus(422);
        // Zero / negative
        $this->placeDpOrder($konsumen, $product, 0)->assertStatus(422);
        // Equal to the total (that's a full payment, not a DP)
        $this->placeDpOrder($konsumen, $product, 1000000)->assertStatus(422);
        // Greater than the total
        $this->placeDpOrder($konsumen, $product, 1500000)->assertStatus(422);

        // Valid
        $this->placeDpOrder($konsumen, $product, 300000)->assertCreated();
    }

    public function test_dp_order_is_created_unpaid_with_the_full_balance_outstanding_and_a_dp_sized_transaction(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 1000000);

        $response = $this->placeDpOrder($konsumen, $product, 300000);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        $this->assertSame('unpaid', $order->payment_status, 'DP must never start as PAID');
        $this->assertEquals(300000, (float) $order->dp_amount);
        $this->assertEquals(0, (float) $order->paid_amount);
        $this->assertEquals(1000000, (float) $order->remaining_amount);
        $this->assertSame('diterima', $order->status, 'a DP order needs payment before processing');

        $transaction = PaymentTransaction::where('order_id', $order->id)->latest()->first();
        $this->assertEquals(300000, (float) $transaction->amount, 'the DP transaction only covers the nominal');
        $this->assertStringStartsWith('DP-', $transaction->gateway_reference);
    }

    public function test_dp_proof_waits_for_verification_and_never_self_settles(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));

        $this->submitProof($konsumen, $order->id)->assertOk();

        $order->refresh();
        $this->assertSame('pending_verification', $order->payment_status);
        $this->assertEquals(0, (float) $order->paid_amount);
        $this->assertEquals(1000000, (float) $order->remaining_amount, 'a submitted DP proof is not money yet');
    }

    public function test_keuangan_verifying_the_dp_yields_partially_paid_not_paid(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $order->refresh();
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertNotSame('paid', $order->payment_status, 'DP verification must never mark the order PAID');
        $this->assertEquals(300000, (float) $order->paid_amount);
        $this->assertEquals(700000, (float) $order->remaining_amount);
    }

    public function test_dp_order_may_only_advance_to_diproses_after_the_dp_is_verified(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));

        // Unpaid DP -> cannot process yet.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertStatus(422);

        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        // partially_paid is enough to start fulfilling a DP order.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->assertSame('diproses', $order->fresh()->status);
    }

    public function test_admin_cannot_verify_a_dp_payment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertStatus(403);
    }

    public function test_settlement_flow_lunasi(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 1000000);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));

        // DP verified -> partially paid.
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        // Keuangan requests settlement of the outstanding 700.000.
        $settle = $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle");
        $settle->assertOk();

        $settlement = PaymentTransaction::where('order_id', $order->id)->orderByDesc('id')->first();
        $this->assertEquals(700000, (float) $settlement->amount);
        $this->assertStringStartsWith('ST-', $settlement->gateway_reference);

        // Konsumen uploads the second proof -> still not paid.
        $this->submitProof($konsumen, $order->id)->assertOk();
        $order->refresh();
        $this->assertSame('pending_verification', $order->payment_status);
        // An unverified proof changes nothing: the balance is still owed.
        $this->assertEquals(700000, (float) $order->remaining_amount);
        $this->assertEquals(300000, (float) $order->paid_amount);

        // ...and Keuangan verifies it -> now (and only now) LUNAS.
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(1000000, (float) $order->paid_amount);
        $this->assertEquals(0, (float) $order->remaining_amount);
        $this->assertTrue($order->isFullyPaid());
    }

    public function test_settlement_is_rejected_when_nothing_is_outstanding_or_the_order_is_not_a_dp(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        // Fully settled DP -> nothing left.
        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 1000000 - 1)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();
        // dp_amount 999999 < total 1000000

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertStatus(422);

        // A non-DP order cannot be "settled".
        $bankOrder = Order::withoutGlobalScopes()->findOrFail(
            $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'payment_method_code' => 'bank_transfer',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                ...$this->destination(),
            ])->json('data.id')
        );

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$bankOrder->id}/payment/settle")->assertStatus(422);
    }

    /**
     * Cancelling only PART of a still-partially-paid DP order's items is a
     * plain cancellation (no refund) as long as the recalculated total still
     * covers what was already paid — the DP remains a normal, unrefunded
     * outstanding-balance obligation. See the sibling test below for the
     * case where the cancellation is large enough to actually overpay.
     */
    public function test_reducing_quantity_on_a_partially_paid_dp_order_cancels_without_any_refund_record(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, price: 700000);
        $productB = $this->makeProduct($agen, price: 300000);

        // Two items so cancelling the smaller one still leaves 700k of total —
        // enough to cover the 300k DP already paid, so never overpaid.
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 300000,
            'items' => [['product_id' => $productA->id, 'quantity' => 1], ['product_id' => $productB->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        // Verify the DP so the order can be processed.
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Stok habis',
        ])->assertOk();

        $itemB->refresh();
        $this->assertSame(0, $itemB->fulfilled_quantity);
        $this->assertSame(1, $itemB->cancelled_quantity);
        // 700k of total remains — still covers the 300k DP already paid, so no overpayment/refund.
        $this->assertSame(0, $itemB->refund_quantity);
        $this->assertDatabaseMissing('order_item_adjustments', ['order_item_id' => $itemB->id]);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
    }

    /**
     * Cancelling enough of a partially-paid DP order that the recalculated
     * total drops BELOW what was already paid genuinely overpays it — this
     * must create a real refund record, not silently swallow the DP.
     * (Previously this codebase only ever refunded a FULLY paid order; a
     * partially-paid DP order that got cancelled down below its own paid
     * amount produced no refund record at all, silently stranding the
     * customer's money — this test locks in the fix.)
     */
    public function test_reducing_the_only_item_on_a_partially_paid_dp_order_below_the_paid_amount_creates_a_refund(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 1000000);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));

        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Batal total',
        ])->assertOk();

        $item->refresh();
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(1, $item->refund_quantity);
        $this->assertDatabaseHas('order_item_adjustments', [
            'order_item_id' => $item->id, 'refund_amount' => 300000, 'refund_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
    }

    public function test_keuangan_from_another_branch_cannot_settle_or_verify_this_payment(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();

        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $otherKeuangan = User::factory()->keuangan()->create(['agent_id' => $otherAgen->id]);

        // Order is hidden by the agent scope for a foreign branch -> 404.
        $this->actingAs($otherKeuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertStatus(404);
        $this->actingAs($otherKeuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertStatus(404);
    }

    public function test_konsumen_cannot_settle_or_verify(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertStatus(403);
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertStatus(403);
    }

    /**
     * "Pelunasan DP merupakan DP SETTLEMENT, bukan Additional Payment" and
     * "Outstanding DP bukan Refund" — the settlement transaction PaymentService::
     * requestSettlement creates must stay type='payment', never 'additional_payment'
     * or 'refund', and must never produce an OrderAdditionalPayment/refund ledger row.
     */
    public function test_dp_settlement_is_a_payment_transaction_never_an_additional_payment_or_refund(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 1000000);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 300000)->json('data.id'));
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();

        $settlement = PaymentTransaction::where('order_id', $order->id)->orderByDesc('id')->first();
        $this->assertSame('payment', $settlement->type);
        $this->assertSame('dp_settlement', $settlement->raw_payload['note']);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_item_adjustments', ['order_item_id' => OrderItem::where('order_id', $order->id)->value('id')]);
    }

    /**
     * OrderResource.payment_summary (PaymentSummaryService) is the canonical
     * shape the Order Detail page and every report read instead of each
     * re-deriving totals — this locks its formula end-to-end through the
     * DP lifecycle: pending -> verified -> settled.
     */
    public function test_order_resource_payment_summary_reflects_the_dp_lifecycle(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 500000);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeDpOrder($konsumen, $product, 200000)->json('data.id'));

        // Pending: DP requested but not yet verified -> nothing counted as paid.
        $pending = $this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}")->json('data.payment_summary');
        $this->assertSame(500000.0, (float) $pending['grand_total']);
        $this->assertSame(200000.0, (float) $pending['requested_dp']);
        $this->assertSame(0.0, (float) $pending['verified_dp']);
        $this->assertSame(0.0, (float) $pending['total_paid']);
        $this->assertSame(500000.0, (float) $pending['remaining_balance']);
        $this->assertFalse($pending['is_fully_paid']);

        // Verified: DP cleared -> verified_dp/total_paid show 200.000, remaining 300.000.
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $verified = $this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}")->json('data.payment_summary');
        $this->assertSame(200000.0, (float) $verified['verified_dp']);
        $this->assertSame(200000.0, (float) $verified['total_paid']);
        $this->assertSame(300000.0, (float) $verified['remaining_balance']);
        $this->assertSame('partially_paid', $verified['payment_status']);
        $this->assertFalse($verified['is_fully_paid']);

        // Settled: pelunasan verified -> total_paid=grand_total, remaining=0, verified_dp
        // stays capped at the ORIGINAL requested DP (200.000), never inflated by the settlement.
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/settle")->assertOk();
        $this->submitProof($konsumen, $order->id)->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $settled = $this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}")->json('data.payment_summary');
        $this->assertSame(200000.0, (float) $settled['verified_dp'], 'the DP tranche itself never changes once verified');
        $this->assertSame(500000.0, (float) $settled['total_paid']);
        $this->assertSame(0.0, (float) $settled['remaining_balance']);
        $this->assertSame('paid', $settled['payment_status']);
        $this->assertTrue($settled['is_fully_paid']);
    }
}
