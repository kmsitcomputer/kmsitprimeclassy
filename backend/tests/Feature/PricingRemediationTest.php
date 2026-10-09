<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductDiscount;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderLineAdditionService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentSummaryService;
use App\Services\Stock\StockRequestProposalService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Codex Audit #1 — Group B durable regressions.
 *
 * A1-06  Targeted (product/variation) vouchers resolve and apply to eligible
 *        cart lines (no longer rejected by default-NULL ids); a cart with
 *        mixed eligible/ineligible lines discounts exactly its eligible
 *        subtotal and only fails when NO eligible line exists. Quote and
 *        Order creation stay identical.
 * A1-07  A FIXED voucher has ONE transaction-level face-value budget — never
 *        once per cart line (line-splitting cannot multiply the discount).
 * A1-08  Voucher orders can still undergo authorized fulfillment reduction:
 *        the canonical calculator clamps the effective voucher to the billed
 *        subtotal — no negative total, no unsigned-DB error, historical
 *        `discount_amount` attribution preserved.
 * A1-09  Promotion precedence filters by validity FIRST: an older active
 *        discount is never masked by a newer future/expired row.
 */
class PricingRemediationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko Remed Price', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $productA = Product::create(['sku' => 'P-'.Str::uuid(), 'name' => 'Kue A', 'slug' => 'kue-a-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
        $productB = Product::create(['sku' => 'P-'.Str::uuid(), 'name' => 'Kue B', 'slug' => 'kue-b-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        // A variation product with a variation row.
        $productV = Product::create(['sku' => null, 'name' => 'Kue V', 'slug' => 'kue-v-'.uniqid(), 'has_variations' => true, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        $variationV = ProductVariation::create(['product_id' => $productV->id, 'sku' => 'PV-'.Str::uuid(), 'name' => 'Kecil', 'price' => 50000, 'weight_grams' => 500, 'is_active' => true]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productV->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $this->b = compact('agen', 'admin', 'konsumen', 'productA', 'productB', 'productV', 'variationV');
    }

    private function postOrder(array $items, ?string $voucherCode = null)
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => $items,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ];
        if ($voucherCode !== null) {
            $payload['voucher_code'] = $voucherCode;
        }

        return $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload);
    }

    private function postQuote(array $items, ?string $voucherCode = null)
    {
        $payload = [
            'items' => $items,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ];
        if ($voucherCode !== null) {
            $payload['voucher_code'] = $voucherCode;
        }

        return $this->actingAs($this->b['konsumen'])->postJson('/api/v1/checkout/quote', $payload);
    }

    // ---- A1-06: targeted vouchers ----

    public function test_product_targeted_voucher_applies_to_matching_cart_line_even_with_mixed_cart(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'A10', 'name' => 'A 10rb', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'product_id' => $this->b['productA']->id]);

        // Cart with BOTH an ineligible line (productB) and the eligible line (productA).
        $quote = $this->postQuote([
            ['product_id' => $this->b['productB']->id, 'quantity' => 1],   // 20000, ineligible
            ['product_id' => $this->b['productA']->id, 'quantity' => 1],   // 100000, eligible
        ], 'A10');
        $quote->assertOk();
        $this->assertSame(10000.0, (float) $quote->json('data.discount_amount'));
        // eligible subtotal (100000) - 10000 => 90000 + productB 20000 = 110000.
        $this->assertSame(110000.0, (float) $quote->json('data.total_amount'));

        // Order creation matches quote.
        $orderRes = $this->postOrder([
            ['product_id' => $this->b['productB']->id, 'quantity' => 1],
            ['product_id' => $this->b['productA']->id, 'quantity' => 1],
        ], 'A10');
        $orderRes->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($orderRes->json('data.id'));
        $this->assertSame(10000.0, (float) $order->discount_amount);
        $this->assertSame(110000.0, (float) $order->total_amount);
    }

    public function test_variation_targeted_voucher_applies_to_matching_variation_line(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'V50', 'name' => 'V 50%', 'type' => 'percentage', 'value' => 50, 'is_active' => true, 'product_variation_id' => $this->b['variationV']->id]);

        // Only the matching variation line is eligible.
        $quote = $this->postQuote([
            ['product_id' => $this->b['productV']->id, 'product_variation_id' => $this->b['variationV']->id, 'quantity' => 2],
        ], 'V50');
        $quote->assertOk();
        // Variation price 50000 * 2 = 100000 eligible; 50% => 50000 discount.
        $this->assertSame(50000.0, (float) $quote->json('data.discount_amount'));
        $this->assertSame(50000.0, (float) $quote->json('data.total_amount'));
    }

    public function test_targeted_voucher_rejected_only_when_no_eligible_line_exists(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'AONLY', 'name' => 'A only', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'product_id' => $this->b['productA']->id]);

        // Cart contains ONLY productB → no eligible line → rejected.
        $this->postOrder([['product_id' => $this->b['productB']->id, 'quantity' => 1]], 'AONLY')->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_fractional_percentage_voucher_is_not_rounded_to_an_integer(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'FRACTION', 'name' => 'Fractional', 'type' => 'percentage', 'value' => 12.5, 'is_active' => true]);
        $lines = [['product_id' => $this->b['productA']->id, 'quantity' => 1]];
        $this->postQuote($lines, 'FRACTION')->assertOk()->assertJsonPath('data.discount_amount', 12500);
        $created = $this->postOrder($lines, 'FRACTION')->assertCreated();
        $this->assertSame(12500.0, (float) $created->json('data.discount_amount'));
    }

    // ---- A1-07: fixed voucher single budget ----

    public function test_fixed_voucher_has_single_face_value_budget_across_multiple_lines(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'FIXED10', 'name' => 'Flat 10rb', 'type' => 'fixed', 'value' => 10000, 'is_active' => true]);

        // Two products (100000 + 20000) — a fixed 10000 voucher must reduce by 10000 TOTAL,
        // never 10000 per line (would be 20000).
        $quote = $this->postQuote([
            ['product_id' => $this->b['productA']->id, 'quantity' => 1],
            ['product_id' => $this->b['productB']->id, 'quantity' => 1],
        ], 'FIXED10');
        $quote->assertOk();
        $this->assertSame(10000.0, (float) $quote->json('data.discount_amount'), 'fixed voucher must be granted once, not per line');
        $this->assertSame(110000.0, (float) $quote->json('data.total_amount')); // 120000 - 10000

        $order = Order::withoutGlobalScopes()->findOrFail($this->postOrder([
            ['product_id' => $this->b['productA']->id, 'quantity' => 1],
            ['product_id' => $this->b['productB']->id, 'quantity' => 1],
        ], 'FIXED10')->json('data.id'));
        $this->assertSame(10000.0, (float) $order->discount_amount);
        $this->assertSame(110000.0, (float) $order->total_amount);
    }

    // ---- A1-08: voucher + fulfillment reduction ----

    public function test_voucher_covered_order_can_be_fully_reduced_without_negative_total_or_db_error(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'BIG', 'name' => 'Big', 'type' => 'fixed', 'value' => 500000, 'is_active' => true]);
        $order = Order::withoutGlobalScopes()->findOrFail($this->postOrder([
            ['product_id' => $this->b['productB']->id, 'quantity' => 1], // 20000
        ], 'BIG')->json('data.id'));
        $this->assertSame(20000.0, (float) $order->discount_amount);
        $this->assertSame(0.0, (float) $order->total_amount);

        // Authorized fulfillment reduction to ZERO active qty — must NOT throw (DB) and must
        // compute a non-negative total via the canonical calculator.
        $item = $order->items()->firstOrFail();
        $admin = $this->b['admin'];
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'A1-08 regression',
        ])->assertOk();

        $order->refresh();
        $this->assertGreaterThanOrEqual(0.0, (float) $order->total_amount, 'total must never be negative');
        $this->assertSame(0.0, (float) $order->subtotal_amount);
        // Historical attribution preserved.
        $this->assertSame(20000.0, (float) $order->discount_amount);
        $this->actingAs($admin)->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.effective_discount_amount', '0.00');
    }

    // ---- A1-09: promotion validity precedence ----

    public function test_older_active_discount_wins_over_newer_future_or_expired_row(): void
    {
        $p = $this->b['productA'];

        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $p->id, 'name' => 'active-20', 'percentage' => 20, 'is_active' => true, 'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString()]);
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $p->id, 'name' => 'future-30', 'percentage' => 30, 'is_active' => true, 'starts_at' => now()->addDay()->toDateString()]);

        // The newer row is FUTURE → the older ACTIVE 20% applies (100000 → 80000).
        $quote = $this->postQuote([['product_id' => $p->id, 'quantity' => 1]]);
        $quote->assertOk();
        $this->assertSame(80000.0, (float) $quote->json('data.subtotal_amount'));

        $order = Order::withoutGlobalScopes()->findOrFail($this->postOrder([['product_id' => $p->id, 'quantity' => 1]])->json('data.id'));
        $this->assertSame(80000.0, (float) $order->subtotal_amount);
    }

    public function test_disabled_newer_row_does_not_mask_older_active_row(): void
    {
        $p = $this->b['productA'];
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $p->id, 'name' => 'active-10', 'percentage' => 10, 'is_active' => true, 'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString()]);
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $p->id, 'name' => 'disabled-50', 'percentage' => 50, 'is_active' => false]);

        $quote = $this->postQuote([['product_id' => $p->id, 'quantity' => 1]]);
        $quote->assertOk();
        $this->assertSame(90000.0, (float) $quote->json('data.subtotal_amount'));
    }

    public function test_discount_voucher_dp_warehouse_sc03_settlement_delivery_and_commission_integrate(): void
    {
        $b = $this->b;
        Storage::fake('public');
        $bank = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create(['agent_id' => $b['agen']->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox', 'config' => ['bank_name' => 'QA', 'account_name' => 'QA', 'account_number' => '123']]);
        $finance = User::factory()->keuangan()->create(['agent_id' => $b['agen']->id]);
        ProductDiscount::create(['agent_id' => $b['agen']->id, 'product_id' => $b['productA']->id, 'name' => 'A20', 'percentage' => 20, 'is_active' => true]);
        ProductDiscount::create(['agent_id' => $b['agen']->id, 'product_id' => $b['productB']->id, 'name' => 'B50', 'percentage' => 50, 'is_active' => true]);
        Voucher::create(['agent_id' => $b['agen']->id, 'code' => 'DPQA', 'name' => 'QA', 'type' => 'fixed', 'value' => 10000, 'is_active' => true]);
        $super = User::factory()->superAdmin()->create();
        foreach ([$b['productA'], $b['productB']] as $product) {
            WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
            $this->actingAs($super)->putJson("/api/v1/products/{$product->id}/fees", ['agent_fee' => 1000, 'sales_fee' => 500, 'courier_fee' => 200])->assertOk();
        }
        $r = $this->actingAs($b['konsumen'])->postJson('/api/v1/orders', ['recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Integration', 'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6] + ['items' => [['product_id' => $b['productA']->id, 'quantity' => 1]], 'payment_method_code' => 'down_payment', 'dp_amount' => 40000, 'voucher_code' => 'DPQA'], ['Idempotency-Key' => 'audit-dp-integrated'])->assertCreated();
        $o = Order::findOrFail($r->json('data.id'));
        $pay = app(PaymentService::class);
        $proof = $pay->submitBankTransferProof($o->paymentTransactions()->first(), UploadedFile::fake()->image('dp.jpg'), $b['konsumen']);
        $pay->verifyBankTransfer($proof, $finance, true);
        $o = $o->fresh();
        $this->assertSame(70000.0, (float) $o->total_amount);
        $this->assertSame(40000.0, (float) $o->paid_amount);
        $this->assertSame(30000.0, (float) $o->remaining_amount);
        $this->actingAs($b['admin']);
        $o = app(OrderService::class)->updateStatus($o, 'diproses', $b['admin']);
        $gudang = User::factory()->gudang()->create(['agent_id' => $b['agen']->id]);
        $proposalService = app(StockRequestProposalService::class);
        $request = $o->stockRequest;
        $proposal = $proposalService->propose($gudang, $request, [['item_id' => $request->items()->firstOrFail()->id, 'quantity' => 1]]);
        $proposalService->approve($b['admin'], $proposal);
        $this->assertSame('fulfilled', $request->fresh()->status);
        $adder = app(OrderLineAdditionService::class);
        [$line] = $adder->addLine($o, ['product_id' => $b['productB']->id, 'quantity' => 1], $b['admin'], null, 'QA', 'transfer', 'audit-add1');
        $o = $o->fresh();
        $this->assertSame(80000.0, (float) $o->total_amount);
        $this->assertSame(40000.0, (float) $o->remaining_amount);
        $this->assertSame(0, $o->additionalPayments()->count());
        $this->assertSame(10000.0, (float) $line->unit_price_snapshot);
        $this->assertSame(80000.0, (float) $o->items()->orderBy('id')->first()->unit_price_snapshot);
        // requestSettlement() returns [PaymentTransaction, bool $wasReplay]; the settlement must be a fresh
        // obligation for exactly the remaining balance (80000 total - 40000 paid = 40000), not a replay.
        [$settlement, $wasReplay] = $pay->requestSettlement($o, $finance);
        $this->assertFalse($wasReplay);
        $this->assertSame(40000.0, (float) $settlement->amount);
        $proof = $pay->submitBankTransferProof($settlement, UploadedFile::fake()->image('settlement.jpg'), $b['konsumen']);
        $pay->verifyBankTransfer($proof, $finance, true);
        $this->assertSame('paid', $o->fresh()->payment_status);
        [$line2] = $adder->addLine($o->fresh(), ['product_id' => $b['productB']->id, 'quantity' => 1], $b['admin'], null, 'QA', 'transfer', 'audit-add2');
        $o = $o->fresh();
        $this->assertSame(90000.0, (float) $o->total_amount);
        $this->assertSame(10000.0, (float) $o->remaining_amount);
        $this->assertSame(1, $o->additionalPayments()->count());
        $this->assertSame(10000.0, (float) $o->additionalPayments()->first()->amount);
        $this->assertSame(1, Voucher::first()->used_count);
        $this->assertSame(3, $o->shipments()->count());
        $this->assertSame(3, $o->stockRequest->items()->count());
        [$replay,$wasReplay] = $adder->addLine($o, ['product_id' => $b['productB']->id, 'quantity' => 1], $b['admin'], null, 'QA replay', 'transfer', 'audit-add2');
        $this->assertTrue($wasReplay);
        $this->assertSame($line2->id, $replay->id);
        $this->assertSame(1, $o->additionalPayments()->count());
        $obligation = $o->additionalPayments()->firstOrFail();
        app(OrderFulfillmentService::class)->markAdditionalPaymentPaid($obligation, $finance, true);
        $o->refresh();
        $this->assertSame(90000.0, (float) $o->paid_amount);
        $this->assertSame(0.0, (float) $o->remaining_amount);
        $request = $o->stockRequest->fresh();
        $this->assertSame('partial', $request->status);
        $remaining = $request->items()->where('remaining_qty', '>', 0)->get()->map(fn ($item) => ['item_id' => $item->id, 'quantity' => $item->remaining_qty])->all();
        $proposalService->approve($b['admin'], $proposalService->propose($gudang, $request, $remaining));
        $this->assertSame('fulfilled', $request->fresh()->status);
        $this->assertSame(0, (int) ProductStock::where('agent_id', $b['agen']->id)->sum('quantity_reserved'));
        $executor = User::factory()->koordinatorKurir()->create(['agent_id' => $b['agen']->id]);
        foreach ($o->shipments()->get() as $shipment) {
            $this->actingAs($executor)->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => 0])->assertOk();
        }
        foreach ($o->shipments()->get() as $shipment) {
            $this->actingAs($executor)->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
            $this->actingAs($executor)->patch("/api/v1/shipments/{$shipment->id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('delivery.jpg')])->assertOk();
            $this->getJson("/api/v1/shipments/{$shipment->id}/receipt")->assertOk();
        }
        $this->assertSame('terkirim', $o->fresh()->status);
        $commissions = Commission::where('order_id', $o->id)->where('beneficiary_role', 'courier')->get();
        $this->assertCount(3, $commissions);
        $this->assertSame([$executor->id], $commissions->pluck('beneficiary_user_id')->unique()->values()->all());
        $this->assertSame(600.0, (float) $commissions->sum('amount'));
        $summary = PaymentSummaryService::summarize($o->fresh());
        $this->assertTrue($summary['is_fully_paid']);
        $this->assertSame(90000.0, $summary['total_paid']);
        $this->assertSame(0.0, $summary['additional_payment_amount']);
    }
}
