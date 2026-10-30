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


    /* ---------------- Super admin — GLOBAL on/off only ---------------- */




    /* ---------------- Agen — per-agen credentials ---------------- */




    /* ---------------- Order creation via a configured gateway ---------------- */


    /* ---------------- Webhooks ---------------- */






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
