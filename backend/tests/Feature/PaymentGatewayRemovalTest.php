<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\Payment\AvailablePaymentMethodService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** External payment gateways were removed by Owner decision; only manual transfer, COD and DP remain payable. */
class PaymentGatewayRemovalTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        return compact('agen', 'konsumen', 'superAdmin');
    }

    public function test_gateway_webhook_endpoint_no_longer_exists(): void
    {
        $this->postJson('/api/v1/webhooks/payment/xendit', [])->assertNotFound();
    }

    public function test_gateway_administration_endpoints_no_longer_exist(): void
    {
        $branch = $this->branch();

        $this->actingAs($branch['superAdmin'])->getJson('/api/v1/admin/payment-gateways')->assertNotFound();
    }

    public function test_agent_environment_switch_no_longer_exists(): void
    {
        $branch = $this->branch();
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();

        $this->actingAs($branch['agen'])
            ->patchJson("/api/v1/agent/payment-methods/{$bank->id}/environment", ['environment' => 'production'])
            ->assertNotFound();
    }

    public function test_a_leftover_gateway_row_cannot_be_toggled_but_manual_and_cod_can(): void
    {
        $branch = $this->branch();
        PaymentMethod::query()->where('code', 'xendit')->delete();
        $gateway = PaymentMethod::create(['code' => 'xendit', 'name' => 'Xendit', 'type' => 'gateway', 'is_active' => false]);

        // Direct-ID toggle against the legacy gateway row is refused and changes nothing.
        $this->actingAs($branch['agen'])
            ->patchJson("/api/v1/agent/payment-methods/{$gateway->id}/toggle")
            ->assertStatus(403);

        $this->assertDatabaseMissing('agent_payment_method_settings', [
            'agent_id' => $branch['agen']->id,
            'payment_method_id' => $gateway->id,
        ]);
        $this->assertFalse($gateway->fresh()->is_active);

        // The supported methods keep working (the guard is not a blanket denial).
        foreach (['bank_transfer', 'cod'] as $code) {
            $method = PaymentMethod::query()->where('code', $code)->firstOrFail();

            $this->actingAs($branch['agen'])
                ->patchJson("/api/v1/agent/payment-methods/{$method->id}/toggle")
                ->assertOk()
                ->assertJsonPath('data.is_active', false);

            $this->assertDatabaseHas('agent_payment_method_settings', [
                'agent_id' => $branch['agen']->id,
                'payment_method_id' => $method->id,
                'is_active' => false,
            ]);
        }

        // Another branch's toggle never touches this branch's override.
        $other = $this->branch();
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        $this->actingAs($other['agen'])
            ->patchJson("/api/v1/agent/payment-methods/{$bank->id}/toggle")
            ->assertOk();

        $this->assertDatabaseHas('agent_payment_method_settings', [
            'agent_id' => $branch['agen']->id,
            'payment_method_id' => $bank->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('agent_payment_method_settings', [
            'agent_id' => $other['agen']->id,
            'payment_method_id' => $bank->id,
            'is_active' => false,
        ]);
        $this->assertNotSame($branch['agen']->id, $other['agen']->id);

        // The historical gateway record is preserved, not deleted.
        $this->assertDatabaseHas('payment_methods', ['id' => $gateway->id, 'code' => 'xendit']);
    }

    public function test_a_leftover_active_gateway_row_is_never_offered_or_payable(): void
    {
        $branch = $this->branch();
        PaymentMethod::query()->where('code', 'xendit')->delete();
        PaymentMethod::create(['code' => 'xendit', 'name' => 'Xendit', 'type' => 'gateway', 'is_active' => true]);

        $offered = app(AvailablePaymentMethodService::class)->availableMethods($branch['agen']->id, null);
        $this->assertFalse($offered->pluck('code')->contains('xendit'));

        $agenList = $this->actingAs($branch['agen'])->getJson('/api/v1/agent/payment-methods')->assertOk();
        $this->assertFalse(collect($agenList->json('data'))->pluck('code')->contains('xendit'));

        $product = Product::create([
            'sku' => 'GW-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-gw-'.uniqid(), 'has_variations' => false,
            'base_price' => 50000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);

        $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'xendit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'G', 'recipient_phone' => '0812', 'address_line' => 'Jl. G',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('payment_transactions', ['payment_method_id' => PaymentMethod::query()->where('code', 'xendit')->value('id')]);
    }
}
