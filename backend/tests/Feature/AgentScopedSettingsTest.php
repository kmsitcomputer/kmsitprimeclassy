<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\ShippingProvider;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agen's own scoped payment-method/shipping-provider toggles — narrower
 * than, and independent of, super_admin's global switches (PaymentGateway/
 * ShippingProvider). Never visible or writable by another agen.
 */
class AgentScopedSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(ShippingProviderSeeder::class);
    }

    private function agen(): User
    {
        $agen = User::factory()->create(['role_id' => Role::where('slug', 'agen')->value('id')]);
        $agen->update(['agent_id' => $agen->id]);

        return $agen;
    }

    /* -----------------------------------------------------------
     * Payment methods
     * ----------------------------------------------------------- */

    public function test_agen_can_list_and_toggle_every_payment_method_including_gateways(): void
    {
        $agen = $this->agen();

        $response = $this->actingAs($agen)->getJson('/api/v1/agent/payment-methods');
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('cod'));
        $this->assertTrue($codes->contains('bank_transfer'));
        $this->assertTrue($codes->contains('xendit'));
        $this->assertTrue($codes->contains('tripay'));
        $this->assertTrue($codes->contains('stripe'));

        $cod = PaymentMethod::query()->where('code', 'cod')->firstOrFail();
        $this->actingAs($agen)->patchJson("/api/v1/agent/payment-methods/{$cod->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $listed = $this->actingAs($agen)->getJson('/api/v1/agent/payment-methods');
        $row = collect($listed->json('data'))->firstWhere('code', 'cod');
        $this->assertFalse($row['is_active']);
        // Global switch is untouched by the agen's own toggle.
        $this->assertTrue($cod->fresh()->is_active);
    }

    public function test_agen_can_toggle_a_gateway_payment_method_and_configure_its_own_credentials(): void
    {
        $agen = $this->agen();
        $xendit = PaymentMethod::query()->where('code', 'xendit')->firstOrFail();

        $this->actingAs($agen)->patchJson("/api/v1/agent/payment-methods/{$xendit->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($agen)->putJson("/api/v1/agent/payment-methods/{$xendit->id}/config", [
            'environment' => 'sandbox',
            'config' => ['secret_key' => 'xnd_key', 'callback_token' => 'tok_abc'],
        ])->assertOk();

        $this->assertDatabaseHas('agent_payment_gateway_configs', [
            'agent_id' => $agen->id, 'payment_method_id' => $xendit->id, 'environment' => 'sandbox',
        ]);
        // Never echoed back.
        $this->assertStringNotContainsString('xnd_key', json_encode(
            $this->actingAs($agen)->getJson('/api/v1/agent/payment-methods')->json()
        ));
    }

    public function test_only_agen_role_may_reach_agent_payment_method_endpoints(): void
    {
        $superAdmin = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

        $this->actingAs($superAdmin)->getJson('/api/v1/agent/payment-methods')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/agent/payment-methods')->assertStatus(403);
    }

    public function test_one_agens_payment_method_toggle_never_affects_another_agen(): void
    {
        $agenA = $this->agen();
        $agenB = $this->agen();
        $cod = PaymentMethod::query()->where('code', 'cod')->firstOrFail();

        $this->actingAs($agenA)->patchJson("/api/v1/agent/payment-methods/{$cod->id}/toggle")->assertOk();

        $bView = $this->actingAs($agenB)->getJson('/api/v1/agent/payment-methods');
        $rowB = collect($bView->json('data'))->firstWhere('code', 'cod');
        $this->assertTrue($rowB['is_active']);
    }

    /* -----------------------------------------------------------
     * Shipping providers
     * ----------------------------------------------------------- */

    public function test_agen_can_list_and_toggle_shipping_providers(): void
    {
        $agen = $this->agen();
        ShippingProvider::query()->update(['is_active' => true]);

        $response = $this->actingAs($agen)->getJson('/api/v1/agent/shipping-providers');
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('rajaongkir'));
        $this->assertTrue($codes->contains('openroute'));

        $rajaOngkir = ShippingProvider::query()->where('code', 'rajaongkir')->firstOrFail();
        $this->actingAs($agen)->patchJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue($rajaOngkir->fresh()->is_active);
    }

    public function test_only_agen_role_may_reach_agent_shipping_provider_endpoints(): void
    {
        $superAdmin = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $this->actingAs($superAdmin)->getJson('/api/v1/agent/shipping-providers')->assertStatus(403);
    }

    public function test_one_agens_shipping_provider_toggle_never_affects_another_agen(): void
    {
        $agenA = $this->agen();
        $agenB = $this->agen();
        ShippingProvider::query()->update(['is_active' => true]);
        $rajaOngkir = ShippingProvider::query()->where('code', 'rajaongkir')->firstOrFail();

        $this->actingAs($agenA)->patchJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/toggle")->assertOk();

        $bView = $this->actingAs($agenB)->getJson('/api/v1/agent/shipping-providers');
        $rowB = collect($bView->json('data'))->firstWhere('code', 'rajaongkir');
        $this->assertTrue($rowB['is_active']);
    }

    /* -----------------------------------------------------------
     * Checkout enforcement — the whole point of these toggles
     * ----------------------------------------------------------- */

    public function test_disabling_cod_for_this_agen_removes_it_from_checkout_steps_for_their_own_konsumen(): void
    {
        $agen = $this->agen();
        $konsumen = User::factory()->create([
            'role_id' => Role::where('slug', 'konsumen')->value('id'), 'agent_id' => $agen->id,
        ]);

        $before = $this->actingAs($konsumen)->getJson('/api/v1/checkout/steps');
        $this->assertTrue(collect($before->json('data.payment_methods'))->pluck('code')->contains('cod'));

        $cod = PaymentMethod::query()->where('code', 'cod')->firstOrFail();
        $this->actingAs($agen)->patchJson("/api/v1/agent/payment-methods/{$cod->id}/toggle")->assertOk();

        $after = $this->actingAs($konsumen)->getJson('/api/v1/checkout/steps');
        $codes = collect($after->json('data.payment_methods'))->pluck('code');
        $this->assertFalse($codes->contains('cod'));
        $this->assertTrue($codes->contains('bank_transfer'));
    }

    public function test_disabling_cod_for_one_agen_never_affects_another_agens_konsumen(): void
    {
        $agenA = $this->agen();
        $agenB = $this->agen();
        $konsumenB = User::factory()->create([
            'role_id' => Role::where('slug', 'konsumen')->value('id'), 'agent_id' => $agenB->id,
        ]);

        $cod = PaymentMethod::query()->where('code', 'cod')->firstOrFail();
        $this->actingAs($agenA)->patchJson("/api/v1/agent/payment-methods/{$cod->id}/toggle")->assertOk();

        $response = $this->actingAs($konsumenB)->getJson('/api/v1/checkout/steps');
        $this->assertTrue(collect($response->json('data.payment_methods'))->pluck('code')->contains('cod'));
    }

    public function test_disabling_rajaongkir_for_this_agen_removes_it_from_checkout_steps_shipping_methods(): void
    {
        $agen = $this->agen();
        ShippingProvider::query()->update(['is_active' => true]);
        $konsumen = User::factory()->create([
            'role_id' => Role::where('slug', 'konsumen')->value('id'), 'agent_id' => $agen->id,
        ]);

        $before = $this->actingAs($konsumen)->getJson('/api/v1/checkout/steps');
        $this->assertTrue(collect($before->json('data.shipping_methods'))->pluck('code')->contains('rajaongkir'));

        $rajaOngkir = ShippingProvider::query()->where('code', 'rajaongkir')->firstOrFail();
        $this->actingAs($agen)->patchJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/toggle")->assertOk();

        $after = $this->actingAs($konsumen)->getJson('/api/v1/checkout/steps');
        $codes = collect($after->json('data.shipping_methods'))->pluck('code');
        $this->assertFalse($codes->contains('rajaongkir'));
        $this->assertTrue($codes->contains('openroute'));
    }
}
