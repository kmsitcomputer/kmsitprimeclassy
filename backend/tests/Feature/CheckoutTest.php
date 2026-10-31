<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id,
            'store_name' => 'Toko Kue Jaya',
            'address' => 'Jl. Merdeka No. 1',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'konsumen');
    }

    private function makeProduct(User $agen, int $price, int $stockQty): Product
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Red Velvet Cake', 'slug' => 'red-velvet-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 1000, 'status' => 'active',
        ]);

        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $product->id,
            'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0,
        ]);

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

    public function test_steps_endpoint_shows_account_step_only_for_guests(): void
    {
        $guestSteps = collect($this->getJson('/api/v1/checkout/steps')->json('data.steps'))->keyBy('key');
        $this->assertTrue($guestSteps['account']['active']);

        ['konsumen' => $konsumen] = $this->makeAgentBranch();
        $authSteps = collect($this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.steps'))->keyBy('key');
        $this->assertFalse($authSteps['account']['active']);
    }

    public function test_referral_is_never_a_standalone_checkout_step_for_staff(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $response = $this->actingAs($agen)->getJson('/api/v1/checkout/steps')->assertOk();
        $steps = collect($response->json('data.steps'))->keyBy('key');
        $this->assertFalse($steps['referral']['active']);
        $this->assertTrue($response->json('data.on_behalf_of_konsumen'));
    }

    public function test_steps_endpoint_keeps_shipping_step_active_for_pickup_without_carrier_provider(): void
    {
        ['konsumen' => $konsumen] = $this->makeAgentBranch();
        ShippingProvider::query()->update(['is_active' => false]);

        // No RajaOngkir/OpenRoute provider active by default -> free shipping, no shipping step.
        $disabled = collect($this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.steps'))->keyBy('key');
        $this->assertTrue($disabled['shipping']['active']);
        $this->assertTrue($this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.shipping_enabled'));

        ShippingProvider::query()->updateOrCreate(['code' => 'openroute'], ['name' => 'OpenRouteService', 'is_active' => true]);

        $enabled = collect($this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.steps'))->keyBy('key');
        $this->assertTrue($enabled['shipping']['active']);
    }

    public function test_quote_computes_totals_without_reserving_stock_or_creating_an_order(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'routes' => [['summary' => ['distance' => 20000, 'duration' => 2400]]],
        ], 200)]);

        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 150000, stockQty: 10);

        $openRoute = ShippingProvider::query()->updateOrCreate(
            ['code' => 'openroute'],
            ['name' => 'OpenRouteService', 'is_active' => true]
        );
        AgentShippingProviderConfig::query()->updateOrCreate(
            ['agent_id' => $agen->id, 'shipping_provider_id' => $openRoute->id],
            ['config' => ['api_key' => 'test-key']]
        );
        // makeAgentBranch() already created this agen's own ShippingConfiguration
        // row — link it to OpenRoute instead of creating a second.
        ShippingConfiguration::query()->where('agent_id', $agen->id)->update([
            'shipping_provider_id' => $openRoute->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0,
        ]);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ...$this->destination(),
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(300000, (float) $data['subtotal_amount']);
        $this->assertEquals(40000, (float) $data['shipping_fee_amount']); // 20km * Rp2.000/km
        $this->assertSame('openroute', $data['shipping_provider']);
        $this->assertEquals(
            (float) $data['subtotal_amount'] + (float) $data['shipping_fee_amount'] + (float) $data['admin_fee_amount'],
            (float) $data['total_amount']
        );
        $this->assertEmpty($data['warnings']);

        $this->assertDatabaseCount('orders', 0);
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertSame(0, $stock->quantity_reserved);
    }

    public function test_quote_warns_on_insufficient_stock_without_blocking_the_request(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 150000, stockQty: 1);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            ...$this->destination(),
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.warnings'));
    }

    public function test_order_creation_requires_a_valid_active_payment_method(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                ...$this->destination(),
                // no payment_method_code at all
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_code');

        // Removed external gateways are never selectable, even when a leftover row exists.
        $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'xendit',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                ...$this->destination(),
            ])
            ->assertStatus(422);
    }

    public function test_missing_idempotency_key_header_is_rejected(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        $this->actingAs($agen)->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_duplicate_submission_with_the_same_idempotency_key_returns_the_original_order_instead_of_creating_a_second_one(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);
        $key = (string) Str::uuid();

        // The codemod pattern used throughout this file: COD is staff-only, so
        // the branch agen places the order on behalf of the buyer. The test's
        // intent (same key => replay returns the original order, nothing doubles)
        // is unchanged.
        $payload = [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ...$this->destination(),
        ];

        $first = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/orders', $payload);
        $first->assertCreated();
        $firstOrderId = $first->json('data.id');

        // Same key, same actor, resubmitted (e.g. a retried network request).
        $second = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/orders', $payload);
        $second->assertCreated();

        $this->assertSame($firstOrderId, $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_initial_report_items', 1);
        $this->assertDatabaseHas('order_initial_report_items', [
            'order_id' => $firstOrderId,
            'agent_id' => $agen->id,
            'initial_quantity' => 2,
            'initial_payment_status' => 'unpaid',
            'item_position' => 1,
        ]);

        // Stock was only ever reserved once, not twice.
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertSame(2, $stock->quantity_reserved);
    }

    public function test_checkout_captures_immutable_initial_snapshot_for_each_original_item(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $firstProduct = $this->makeProduct($agen, price: 100000, stockQty: 10);
        $secondProduct = $this->makeProduct($agen, price: 75000, stockQty: 10);

        $response = $this->actingAs($agen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
                'payment_method_code' => 'cod',
                'items' => [
                    ['product_id' => $firstProduct->id, 'quantity' => 2],
                    ['product_id' => $secondProduct->id, 'quantity' => 3],
                ],
                ...$this->destination(),
            ]);

        $response->assertCreated();
        $orderId = $response->json('data.id');

        $snapshots = \App\Models\OrderInitialReportItem::withoutGlobalScopes()
            ->where('order_id', $orderId)
            ->orderBy('item_position')
            ->get();

        $this->assertCount(2, $snapshots);
        $this->assertSame([1, 2], $snapshots->pluck('item_position')->all());
        $this->assertSame(
            [$firstProduct->id, $secondProduct->id],
            $snapshots->pluck('product_id')->all()
        );
        $this->assertSame([2, 3], $snapshots->pluck('initial_quantity')->all());
        $this->assertEquals(200000, (float) $snapshots[0]->item_subtotal_snapshot);
        $this->assertEquals(225000, (float) $snapshots[1]->item_subtotal_snapshot);

        foreach ($snapshots as $snapshot) {
            $this->assertSame($agen->id, $snapshot->agent_id);
            $this->assertSame('Budi', $snapshot->recipient_name_snapshot);
            $this->assertSame('unpaid', $snapshot->initial_payment_status);
            $this->assertEquals(0, (float) $snapshot->initial_paid_snapshot);
            $this->assertEquals(
                (float) $snapshot->invoice_total_snapshot,
                (float) $snapshot->initial_remaining_snapshot
            );
        }

        $this->assertEquals(
            (float) $response->json('data.total_amount'),
            (float) $snapshots[0]->invoice_total_snapshot
        );
        $this->assertEquals(
            (float) $snapshots[0]->invoice_total_snapshot,
            (float) $snapshots[1]->invoice_total_snapshot
        );
    }

    public function test_cod_order_creates_a_pending_transaction_with_no_verification_required(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        $response = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertSame('cod', $response->json('data.payment_method.type'));
        $this->assertSame('pending', $response->json('data.payment_transaction.status'));
        $this->assertSame('unpaid', $this->actingAs($konsumen)->getJson('/api/v1/orders/'.$response->json('data.id'))->json('data.payment_status'));
    }

    public function test_bank_transfer_requires_configuration_before_it_can_be_selected(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        // No payment_gateway_configs row exists yet for bank_transfer.
        $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'bank_transfer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->assertStatus(422);
    }

    public function test_bank_transfer_full_flow_proof_upload_then_admin_verification_marks_order_paid(): void
    {
        Storage::fake('public');

        $bankTransfer = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        // phpunit.xml sets APP_ENV=testing, which PaymentService::activeConfigFor
        // resolves to the 'sandbox' slot of this agen's own config.
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id,
            'payment_method_id' => $bankTransfer->id,
            'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime Classy', 'account_number' => '1234567890'],
        ]);
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        $orderResponse = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'bank_transfer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('data.id');

        $this->assertSame('BCA', $orderResponse->json('data.payment_transaction.instructions.bank_name'));

        $initialSnapshotBeforePayment = \App\Models\OrderInitialReportItem::withoutGlobalScopes()
            ->where('order_id', $orderId)
            ->firstOrFail()
            ->only([
                'order_no', 'initial_quantity', 'item_subtotal_snapshot',
                'invoice_total_snapshot', 'initial_paid_snapshot',
                'initial_remaining_snapshot', 'initial_payment_status',
            ]);

        $this->assertSame('unpaid', $initialSnapshotBeforePayment['initial_payment_status']);
        $this->assertEquals(0, (float) $initialSnapshotBeforePayment['initial_paid_snapshot']);

        $proof = UploadedFile::fake()->image('proof.jpg');
        $proofResponse = $this->actingAs($konsumen)->postJson("/api/v1/orders/{$orderId}/payment/proof", ['proof' => $proof]);
        $proofResponse->assertOk();

        $this->assertSame(
            'pending_verification',
            $this->actingAs($konsumen)->getJson("/api/v1/orders/{$orderId}")->json('data.payment_status')
        );

        // konsumen (not staff) cannot verify their own payment.
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$orderId}/payment/verify", ['approved' => true])->assertStatus(403);

        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $verifyResponse = $this->actingAs($keuangan)->postJson("/api/v1/orders/{$orderId}/payment/verify", ['approved' => true]);
        $verifyResponse->assertOk();

        $this->assertSame(
            'paid',
            $this->actingAs($konsumen)->getJson("/api/v1/orders/{$orderId}")->json('data.payment_status')
        );

        $initialSnapshotAfterPayment = \App\Models\OrderInitialReportItem::withoutGlobalScopes()
            ->where('order_id', $orderId)
            ->firstOrFail()
            ->only(array_keys($initialSnapshotBeforePayment));

        $this->assertSame($initialSnapshotBeforePayment, $initialSnapshotAfterPayment);
        $this->assertDatabaseCount('order_initial_report_items', 1);

        // A second verification attempt on the same, already-resolved transaction is rejected.
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$orderId}/payment/verify", ['approved' => true])->assertStatus(422);
    }

    public function test_no_shipping_provider_active_makes_the_order_free_shipping(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 5);

        // Neither RajaOngkir nor OpenRoute is active (the seeded default) -> free shipping.
        $response = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(0, (float) $response->json('data.shipping_fee_amount'));
    }
}
