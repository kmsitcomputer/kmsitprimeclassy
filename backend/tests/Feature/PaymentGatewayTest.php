<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentPaymentMethodSetting;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'admin', 'konsumen');
    }

    private function makeProduct(User $agen, int $price = 100000, int $stockQty = 10): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'QA Cake', 'slug' => 'qa-cake-'.uniqid(),
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

    /** Configures Xendit sandbox credentials for a given agen (agent-scoped, per the new architecture). */
    private function configureXenditSandbox(User $agen, string $callbackToken = 'tok_abc123'): PaymentMethod
    {
        $xendit = PaymentMethod::where('code', 'xendit')->first();
        $xendit->update(['is_active' => true]);
        AgentPaymentMethodSetting::updateOrCreate(
            ['agent_id' => $agen->id, 'payment_method_id' => $xendit->id],
            ['active_environment' => 'sandbox'],
        );
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $xendit->id, 'environment' => 'sandbox',
            'config' => ['secret_key' => 'xnd_test_key', 'callback_token' => $callbackToken],
        ]);

        return $xendit->fresh();
    }

    /* ---------------- Super admin — GLOBAL on/off only ---------------- */

    public function test_super_admin_can_list_and_toggle_gateways_but_never_sees_credentials(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen, 'secret-token-should-never-leak');

        $response = $this->actingAs($this->superAdmin())->getJson('/api/v1/admin/payment-gateways');
        $response->assertOk();

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('secret-token-should-never-leak', $json);
        $this->assertStringNotContainsString('xnd_test_key', $json);

        $xendit = PaymentMethod::where('code', 'xendit')->first();
        $admin = $this->superAdmin();

        // configureXenditSandbox() already activated it — the first toggle here turns it off.
        $this->actingAs($admin)->patchJson("/api/v1/admin/payment-gateways/{$xendit->id}/toggle")->assertOk();
        $this->assertFalse($xendit->fresh()->is_active);
        $this->actingAs($admin)->patchJson("/api/v1/admin/payment-gateways/{$xendit->id}/toggle")->assertOk();
        $this->assertTrue($xendit->fresh()->is_active);

        $this->assertDatabaseHas('activity_logs', ['event' => 'payment_gateway.toggled']);
    }

    public function test_non_super_admin_cannot_access_gateway_administration(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->actingAs($agen)->getJson('/api/v1/admin/payment-gateways')->assertStatus(403);
    }

    public function test_super_admin_no_longer_has_a_config_endpoint(): void
    {
        $xendit = PaymentMethod::where('code', 'xendit')->first();

        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/admin/payment-gateways/{$xendit->id}/config", [
                'environment' => 'sandbox',
                'config' => ['secret_key' => 'xnd_test_key', 'callback_token' => 'tok_abc'],
            ])
            ->assertStatus(404);
    }

    /* ---------------- Agen — per-agen credentials ---------------- */

    public function test_agen_can_save_own_credentials_toggle_and_change_environment(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $xendit = PaymentMethod::where('code', 'xendit')->first();

        $this->actingAs($agen)->putJson("/api/v1/agent/payment-methods/{$xendit->id}/config", [
            'environment' => 'sandbox',
            'config' => ['secret_key' => 'xnd_test_key', 'callback_token' => 'tok_abc'],
        ])->assertOk();

        $this->assertDatabaseHas('agent_payment_gateway_configs', [
            'agent_id' => $agen->id, 'payment_method_id' => $xendit->id, 'environment' => 'sandbox',
        ]);

        $this->actingAs($agen)->patchJson("/api/v1/agent/payment-methods/{$xendit->id}/toggle")->assertOk();
        $this->assertDatabaseHas('agent_payment_method_settings', ['agent_id' => $agen->id, 'payment_method_id' => $xendit->id, 'is_active' => false]);

        $this->actingAs($agen)->patchJson("/api/v1/agent/payment-methods/{$xendit->id}/environment", ['environment' => 'production'])->assertOk();
        $this->assertDatabaseHas('agent_payment_method_settings', ['agent_id' => $agen->id, 'payment_method_id' => $xendit->id, 'active_environment' => 'production']);

        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_payment_method.config_updated']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_payment_method.toggled']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_payment_method.environment_changed']);
    }

    public function test_updating_agent_gateway_config_validates_required_fields_per_provider(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $xendit = PaymentMethod::where('code', 'xendit')->first();

        $this->actingAs($agen)
            ->putJson("/api/v1/agent/payment-methods/{$xendit->id}/config", [
                'config' => ['secret_key' => 'xnd_test_key'], // missing callback_token
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('config.callback_token');
    }

    public function test_agent_credentials_are_never_echoed_back_and_isolated_between_agents(): void
    {
        ['agen' => $agenA] = $this->makeAgentBranch();
        ['agen' => $agenB] = $this->makeAgentBranch();

        $this->configureXenditSandbox($agenA, 'token-for-agent-a');
        $xendit = PaymentMethod::where('code', 'xendit')->first();

        $this->actingAs($agenB)->putJson("/api/v1/agent/payment-methods/{$xendit->id}/config", [
            'environment' => 'sandbox',
            'config' => ['secret_key' => 'xnd_b_key', 'callback_token' => 'token-for-agent-b'],
        ])->assertOk();

        // Agen B's write never touches agen A's row.
        $this->assertDatabaseHas('agent_payment_gateway_configs', ['agent_id' => $agenA->id, 'payment_method_id' => $xendit->id]);
        $this->assertDatabaseHas('agent_payment_gateway_configs', ['agent_id' => $agenB->id, 'payment_method_id' => $xendit->id]);
        $this->assertDatabaseCount('agent_payment_gateway_configs', 2);

        $listResponse = $this->actingAs($agenB)->getJson('/api/v1/agent/payment-methods');
        $json = json_encode($listResponse->json());
        $this->assertStringNotContainsString('token-for-agent-a', $json);
        $this->assertStringNotContainsString('token-for-agent-b', $json);
    }

    /* ---------------- Order creation via a configured gateway ---------------- */

    public function test_order_via_gateway_calls_the_provider_and_stores_its_reference(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen);
        Http::fake([
            'api.xendit.co/*' => Http::response(['id' => 'inv_abc123', 'invoice_url' => 'https://checkout.xendit.co/inv_abc123'], 200),
        ]);

        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertSame('inv_abc123', $response->json('data.payment_transaction.gateway_reference'));
        $this->assertSame('https://checkout.xendit.co/inv_abc123', $response->json('data.payment_transaction.instructions.invoice_url'));
    }

    /* ---------------- Webhooks ---------------- */

    public function test_xendit_webhook_with_valid_token_marks_transaction_paid_and_allows_order_processing(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen);
        Http::fake(['api.xendit.co/*' => Http::response(['id' => 'inv_abc123', 'invoice_url' => 'https://x'], 200)]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        // Cannot advance to diproses before the gateway confirms payment.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'diproses'])->assertStatus(422);

        $webhook = $this->postJson('/api/v1/webhooks/payment/xendit', [
            'id' => 'inv_abc123', 'external_id' => 'whatever', 'status' => 'PAID', 'paid_amount' => 100000 + (float) Order::withoutGlobalScopes()->find($orderId)->shipping_fee_amount,
        ], ['x-callback-token' => 'tok_abc123']);
        $webhook->assertOk();

        $order = Order::withoutGlobalScopes()->find($orderId);
        $this->assertSame('paid', $order->payment_status);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'diproses'])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.webhook_processed']);
        $this->assertDatabaseCount('payment_webhook_logs', 1);
    }

    public function test_webhook_is_verified_against_the_owning_agents_own_credentials_only(): void
    {
        ['agen' => $agenA, 'konsumen' => $konsumenA] = $this->makeAgentBranch();
        ['agen' => $agenB] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agenA, 'token-for-agent-a');
        $this->configureXenditSandbox($agenB, 'token-for-agent-b');

        Http::fake(['api.xendit.co/*' => Http::response(['id' => 'inv_agent_a', 'invoice_url' => 'https://x'], 200)]);
        $product = $this->makeProduct($agenA);

        $orderId = $this->actingAs($konsumenA)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        // Agent B's token must NOT validate agent A's transaction.
        $this->postJson('/api/v1/webhooks/payment/xendit', [
            'id' => 'inv_agent_a', 'status' => 'PAID',
        ], ['x-callback-token' => 'token-for-agent-b'])->assertStatus(401);
        $this->assertSame('unpaid', Order::withoutGlobalScopes()->find($orderId)->payment_status);

        // Agent A's own token validates it correctly.
        $this->postJson('/api/v1/webhooks/payment/xendit', [
            'id' => 'inv_agent_a', 'status' => 'PAID',
        ], ['x-callback-token' => 'token-for-agent-a'])->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($orderId)->payment_status);
    }

    public function test_webhook_with_invalid_signature_is_rejected_and_does_not_change_transaction(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen);
        Http::fake(['api.xendit.co/*' => Http::response(['id' => 'inv_abc123', 'invoice_url' => 'https://x'], 200)]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $this->postJson('/api/v1/webhooks/payment/xendit', [
            'id' => 'inv_abc123', 'status' => 'PAID',
        ], ['x-callback-token' => 'wrong-token'])->assertStatus(401);

        $this->assertSame('unpaid', Order::withoutGlobalScopes()->find($orderId)->payment_status);
    }

    public function test_duplicate_webhook_event_is_processed_only_once(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen);
        Http::fake(['api.xendit.co/*' => Http::response(['id' => 'inv_abc123', 'invoice_url' => 'https://x'], 200)]);

        $product = $this->makeProduct($agen);

        $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);

        $body = ['id' => 'inv_abc123', 'status' => 'PAID'];
        $headers = ['x-callback-token' => 'tok_abc123'];

        $first = $this->postJson('/api/v1/webhooks/payment/xendit', $body, $headers);
        $first->assertOk();
        $second = $this->postJson('/api/v1/webhooks/payment/xendit', $body, $headers);
        $second->assertOk();

        $this->assertDatabaseCount('payment_webhook_logs', 1);
        $this->assertSame(1, ActivityLog::where('event', 'payment.webhook_processed')->count());
    }

    public function test_webhook_for_unknown_reference_is_rejected(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->configureXenditSandbox($agen);

        $this->postJson('/api/v1/webhooks/payment/xendit', [
            'id' => 'inv_never_existed', 'status' => 'PAID',
        ], ['x-callback-token' => 'tok_abc123'])->assertStatus(422);

        $this->assertDatabaseCount('payment_webhook_logs', 1);
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_reference' => 'inv_never_existed', 'processed' => false]);
    }

    /* ---------------- Order/payment status coupling ---------------- */

    public function test_manual_transfer_order_stays_diterima_until_verified_then_can_advance(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->first();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'bank_transfer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $this->assertSame('diterima', Order::withoutGlobalScopes()->find($orderId)->status);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'diproses'])->assertStatus(422);
        $this->assertSame('diterima', Order::withoutGlobalScopes()->find($orderId)->status, 'unverified manual transfer must stay diterima');

        $transaction = PaymentTransaction::where('order_id', $orderId)->latest()->first();
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$orderId}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();

        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$orderId}/payment/verify", ['approved' => true])->assertOk();

        // Separation of duties: ADMIN may no longer verify payments.
        $this->actingAs($admin)->postJson("/api/v1/orders/{$orderId}/payment/verify", ['approved' => true])->assertStatus(403);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'diproses'])->assertOk();
        $this->assertSame('diproses', Order::withoutGlobalScopes()->find($orderId)->status);
    }

    public function test_cod_order_is_created_directly_into_diproses(): void
    {
        ['konsumen' => $konsumen, 'agen' => $agen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        // COD needs no payment-verification step, so it skips 'diterima' entirely.
        $this->assertSame('diproses', Order::withoutGlobalScopes()->find($orderId)->status);
    }

    public function test_only_keuangan_of_the_branch_may_mark_cod_payment_paid_and_it_is_audited(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $this->assertSame('unpaid', Order::withoutGlobalScopes()->find($orderId)->payment_status);

        // konsumen cannot mark their own COD order paid.
        $this->actingAs($konsumen)->patchJson("/api/v1/orders/{$orderId}/payment/cod", ['paid' => true])->assertStatus(403);

        // Separation of duties: ADMIN may not settle COD — only KEUANGAN.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$orderId}/payment/cod", ['paid' => true])->assertStatus(403);

        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $markPaidResponse = $this->actingAs($keuangan)->patchJson("/api/v1/orders/{$orderId}/payment/cod", ['paid' => true]);
        $markPaidResponse->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($orderId)->payment_status);
        $this->assertSame('Cash on Delivery', $markPaidResponse->json('data.payment_method.name'));
        // Same relations as show()/updateStatus() — the order-detail page renders straight
        // off this response and crashes (blank screen) if 'couriers' is silently missing.
        $this->assertNotNull($markPaidResponse->json('data.couriers'));

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'payment.cod_status_changed', 'causer_id' => $keuangan->id,
        ]);
    }

    public function test_tripay_webhook_signature_is_verified_via_hmac_of_the_raw_body(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $tripay = PaymentMethod::where('code', 'tripay')->first();
        $tripay->update(['is_active' => true]);
        AgentPaymentMethodSetting::updateOrCreate(
            ['agent_id' => $agen->id, 'payment_method_id' => $tripay->id],
            ['active_environment' => 'sandbox'],
        );
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $tripay->id, 'environment' => 'sandbox',
            'config' => ['merchant_code' => 'M001', 'private_key' => 'priv_secret', 'api_key' => 'api_key'],
        ]);
        Http::fake(['tripay.co.id/*' => Http::response(['data' => ['reference' => 'T-ABC123', 'checkout_url' => 'https://tripay/pay']], 200)]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'tripay',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $body = ['reference' => 'T-ABC123', 'status' => 'PAID', 'total_amount' => (float) Order::withoutGlobalScopes()->find($orderId)->total_amount];
        $rawBody = json_encode($body);
        $validSignature = hash_hmac('sha256', $rawBody, 'priv_secret');

        // Wrong signature: rejected, order untouched.
        $this->call('POST', '/api/v1/webhooks/payment/tripay', [], [], [], [
            'HTTP_X-Callback-Signature' => 'deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertStatus(401);
        $this->assertSame('unpaid', Order::withoutGlobalScopes()->find($orderId)->payment_status);

        // Correct HMAC: accepted and processed.
        $this->call('POST', '/api/v1/webhooks/payment/tripay', [], [], [], [
            'HTTP_X-Callback-Signature' => $validSignature, 'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($orderId)->payment_status);
    }

    public function test_stripe_webhook_signature_uses_timestamped_hmac_and_rejects_stale_timestamps(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $stripe = PaymentMethod::where('code', 'stripe')->first();
        $stripe->update(['is_active' => true]);
        AgentPaymentMethodSetting::updateOrCreate(
            ['agent_id' => $agen->id, 'payment_method_id' => $stripe->id],
            ['active_environment' => 'sandbox'],
        );
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $stripe->id, 'environment' => 'sandbox',
            'config' => ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test'],
        ]);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_abc123', 'client_secret' => 'secret'], 200)]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'stripe',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $rawBody = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_abc123']]]);

        // Stale timestamp (outside the 5-minute tolerance) is rejected even with a mathematically correct HMAC.
        $staleTs = time() - 3600;
        $staleSig = 'v1='.hash_hmac('sha256', $staleTs.'.'.$rawBody, 'whsec_test');
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$staleTs},{$staleSig}", 'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertStatus(401);
        $this->assertSame('unpaid', Order::withoutGlobalScopes()->find($orderId)->payment_status);

        $ts = time();
        $sig = 'v1='.hash_hmac('sha256', $ts.'.'.$rawBody, 'whsec_test');
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$ts},{$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($orderId)->payment_status);
    }

    public function test_marking_cod_payment_status_is_rejected_for_non_cod_orders(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->first();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);

        $product = $this->makeProduct($agen);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'bank_transfer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ])->json('data.id');

        $this->actingAs(User::factory()->keuangan()->create(['agent_id' => $agen->id]))
            ->patchJson("/api/v1/orders/{$orderId}/payment/cod", ['paid' => true])->assertStatus(422);
    }
}
