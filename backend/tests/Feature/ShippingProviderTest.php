<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Regency;
use App\Models\Role;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class ShippingProviderTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(ShippingProviderSeeder::class);
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
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'konsumen');
    }

    private function makeProduct(User $agen, int $price = 100000, int $stockQty = 10, int $weightGrams = 1000): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'QA Cake', 'slug' => 'qa-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => $weightGrams, 'status' => 'active',
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

    /** Activates OpenRoute globally and configures this agen's own credentials + rate. */
    private function configureOpenRoute(User $agen, float $pricePerKm = 2000, float $minimumDistanceKm = 0, float $minimumCharge = 0, string $apiKey = 'test-key'): ShippingProvider
    {
        $openRoute = ShippingProvider::where('code', 'openroute')->first();
        $openRoute->update(['is_active' => true]);
        AgentShippingProviderConfig::updateOrCreate(
            ['agent_id' => $agen->id, 'shipping_provider_id' => $openRoute->id],
            ['config' => ['api_key' => $apiKey]],
        );
        ShippingConfiguration::updateOrCreate(
            ['agent_id' => $agen->id],
            [
                'shipping_provider_id' => $openRoute->id,
                'price_per_km' => $pricePerKm, 'minimum_distance_km' => $minimumDistanceKm, 'minimum_charge' => $minimumCharge,
                'free_shipping_enabled' => false, 'is_active' => true,
            ],
        );

        return $openRoute->fresh();
    }

    /** Activates RajaOngkir globally and configures this agen's own credentials. */
    private function configureRajaOngkir(User $agen, array $couriers = ['jne']): ShippingProvider
    {
        $rajaOngkir = ShippingProvider::where('code', 'rajaongkir')->first();
        $rajaOngkir->update(['is_active' => true]);
        AgentShippingProviderConfig::updateOrCreate(
            ['agent_id' => $agen->id, 'shipping_provider_id' => $rajaOngkir->id],
            ['config' => ['api_key' => 'test-key', 'account_type' => 'starter', 'origin_city_id' => '152', 'couriers' => $couriers]],
        );

        return $rajaOngkir->fresh();
    }

    /* ---------------- Defaults / precedence ---------------- */

    public function test_both_providers_disabled_by_default_means_free_shipping(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(0, (float) $response->json('data.shipping_fee_amount'));
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'free']);
    }

    /* ---------------- Super admin — GLOBAL on/off only ---------------- */

    public function test_super_admin_can_list_and_toggle_providers_but_never_sees_credentials(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->configureOpenRoute($agen, apiKey: 'super-secret-openroute-key');
        $openRoute = ShippingProvider::where('code', 'openroute')->first();
        $admin = $this->superAdmin();

        $list = $this->actingAs($admin)->getJson('/api/v1/admin/shipping-providers');
        $list->assertOk();
        $this->assertStringNotContainsString('super-secret-openroute-key', json_encode($list->json()));

        $this->actingAs($admin)->patchJson("/api/v1/admin/shipping-providers/{$openRoute->id}/toggle")->assertOk();
        $this->assertFalse($openRoute->fresh()->is_active);

        $this->assertDatabaseHas('activity_logs', ['event' => 'shipping_provider.toggled']);
    }

    public function test_non_super_admin_cannot_manage_shipping_providers(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->actingAs($agen)->getJson('/api/v1/admin/shipping-providers')->assertStatus(403);
    }

    public function test_super_admin_no_longer_has_a_config_endpoint(): void
    {
        $openRoute = ShippingProvider::where('code', 'openroute')->first();

        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/admin/shipping-providers/{$openRoute->id}/config", [
                'config' => ['api_key' => 'super-secret-openroute-key'],
                'price_per_km' => 3000,
                'minimum_distance_km' => 2,
            ])
            ->assertStatus(404);
    }

    /* ---------------- Agen — per-agen credentials ---------------- */

    public function test_agen_can_save_own_credentials_and_rate_and_toggle(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $openRoute = ShippingProvider::where('code', 'openroute')->first();

        $response = $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$openRoute->id}/config", [
            'config' => ['api_key' => 'super-secret-openroute-key'],
            'price_per_km' => 3000,
            'minimum_distance_km' => 2,
        ]);
        $response->assertOk();
        $this->assertStringNotContainsString('super-secret-openroute-key', json_encode($response->json()));

        $this->assertDatabaseHas('agent_shipping_provider_configs', ['agent_id' => $agen->id, 'shipping_provider_id' => $openRoute->id]);
        $this->assertDatabaseHas('shipping_configurations', ['agent_id' => $agen->id, 'shipping_provider_id' => $openRoute->id, 'price_per_km' => 3000]);

        $list = $this->actingAs($agen)->getJson('/api/v1/agent/shipping-providers');
        $row = collect($list->json('data'))->firstWhere('code', 'openroute');
        $this->assertTrue($row['configured']);

        $this->actingAs($agen)->patchJson("/api/v1/agent/shipping-providers/{$openRoute->id}/toggle")->assertOk();
        $this->assertDatabaseHas('agent_shipping_provider_settings', ['agent_id' => $agen->id, 'shipping_provider_id' => $openRoute->id, 'is_active' => false]);

        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_shipping_provider.config_updated']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_shipping_provider.toggled']);
    }

    public function test_rajaongkir_config_validates_required_fields(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $rajaOngkir = ShippingProvider::where('code', 'rajaongkir')->first();

        $this->actingAs($agen)
            ->putJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/config", [
                'config' => ['api_key' => 'key-only'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config.account_type', 'config.origin_city_id', 'config.couriers']);
    }

    public function test_agent_credentials_and_rates_are_isolated_between_agents(): void
    {
        ['agen' => $agenA] = $this->makeAgentBranch();
        ['agen' => $agenB] = $this->makeAgentBranch();
        $this->configureOpenRoute($agenA, pricePerKm: 1000);

        $openRoute = ShippingProvider::where('code', 'openroute')->first();
        $this->actingAs($agenB)->putJson("/api/v1/agent/shipping-providers/{$openRoute->id}/config", [
            'config' => ['api_key' => 'agent-b-key'],
            'price_per_km' => 5000,
            'minimum_distance_km' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('shipping_configurations', ['agent_id' => $agenA->id, 'price_per_km' => 1000]);
        $this->assertDatabaseHas('shipping_configurations', ['agent_id' => $agenB->id, 'price_per_km' => 5000]);
    }

    /* ---------------- OpenRoute distance-threshold rule ---------------- */

    public function test_openroute_applies_free_shipping_below_minimum_distance_and_rate_above_it(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureOpenRoute($agen, pricePerKm: 2000, minimumDistanceKm: 5);

        // Http::fake() with the same URL pattern registered twice keeps
        // matching the FIRST stub, not the most recent — a sequence is the
        // correct way to script two different responses from the same route.
        Http::fake(['api.openrouteservice.org/*' => Http::sequence()
            ->push(['routes' => [['summary' => ['distance' => 3000]]]], 200)
            ->push(['routes' => [['summary' => ['distance' => 10000]]]], 200)]);

        // Below the 5km threshold -> free.
        $near = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(),
        ]);
        $near->assertCreated();
        $this->assertEquals(0, (float) $near->json('data.shipping_fee_amount'));
        $this->assertDatabaseHas('shipments', ['order_id' => $near->json('data.id'), 'distance_km' => 3.0, 'shipping_provider_code' => 'openroute']);

        // At/above the threshold -> full distance * price_per_km. A different
        // destination so this doesn't hit the first call's cached distance.
        $far = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$this->destination(), 'latitude' => -6.900000, 'longitude' => 107.650000,
        ]);
        $far->assertCreated();
        $this->assertEquals(20000, (float) $far->json('data.shipping_fee_amount')); // 10km * 2000
    }

    public function test_openroute_http_failure_falls_back_to_haversine_instead_of_blocking_checkout(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureOpenRoute($agen);

        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        // Never blocked — falls back to the Haversine distance and still charges correctly.
        $response->assertCreated();
        $this->assertGreaterThan(0, (float) $response->json('data.shipping_fee_amount'));
    }

    /* ---------------- RajaOngkir ---------------- */

    public function test_rajaongkir_quotes_the_cheapest_courier_service_and_takes_precedence_over_openroute(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen);
        // OpenRoute also enabled — RajaOngkir must win (precedence).
        $this->configureOpenRoute($agen, pricePerKm: 999999);

        Http::fake(['api.rajaongkir.com/*' => Http::response(['rajaongkir' => ['results' => [[
            'code' => 'jne', 'costs' => [
                ['service' => 'OKE', 'cost' => [['value' => 18000, 'etd' => '2-3']]],
                ['service' => 'REG', 'cost' => [['value' => 25000, 'etd' => '1-2']]],
            ],
        ]]]], 200)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(18000, (float) $response->json('data.shipping_fee_amount')); // cheapest of the two services
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'rajaongkir']);
    }

    public function test_rajaongkir_falls_back_to_openroute_when_destination_regency_has_no_mapping(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        // Deliberately no rajaongkir_city_id set on the test regency.

        $this->configureRajaOngkir($agen);
        $this->configureOpenRoute($agen);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 5000]]]], 200)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'openroute']);
        $this->assertEquals(10000, (float) $response->json('data.shipping_fee_amount')); // 5km * 2000
    }

    /* ---------------- Konsumen shipping-method choice: Ekspedisi vs Kurir Online ---------------- */

    private function activateBothProvidersForMethodChoice(User $agen): void
    {
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen);
        $this->configureOpenRoute($agen);
    }

    public function test_checkout_steps_exposes_ekspedisi_and_kurir_online_only_when_both_active(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();

        $none = $this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.shipping_methods');
        $this->assertEmpty($none);

        $this->activateBothProvidersForMethodChoice($agen);

        $both = collect($this->actingAs($konsumen)->getJson('/api/v1/checkout/steps')->json('data.shipping_methods'))->keyBy('code');
        $this->assertSame('Ekspedisi', $both['rajaongkir']['label']);
        $this->assertSame('Kurir Online', $both['openroute']['label']);
    }

    public function test_konsumen_can_pick_kurir_online_even_though_rajaongkir_would_normally_win_by_precedence(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $this->activateBothProvidersForMethodChoice($agen);

        Http::fake([
            'api.rajaongkir.com/*' => Http::response(['rajaongkir' => ['results' => [[
                'code' => 'jne', 'costs' => [['service' => 'OKE', 'cost' => [['value' => 18000]]]],
            ]]]], 200),
            'api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 10000]]]], 200),
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'openroute',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'openroute']);
        $this->assertEquals(20000, (float) $response->json('data.shipping_fee_amount')); // 10km * 2000, NOT the 18000 RajaOngkir quote
    }

    public function test_konsumen_can_pick_ekspedisi_explicitly(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $this->activateBothProvidersForMethodChoice($agen);

        Http::fake(['api.rajaongkir.com/*' => Http::response(['rajaongkir' => ['results' => [[
            'code' => 'jne', 'costs' => [['service' => 'OKE', 'cost' => [['value' => 18000]]]],
        ]]]], 200)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'rajaongkir',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'rajaongkir']);
        $this->assertEquals(18000, (float) $response->json('data.shipping_fee_amount'));
    }

    public function test_picking_a_shipping_method_that_fails_falls_back_to_free_not_the_other_provider(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $this->activateBothProvidersForMethodChoice($agen);

        // RajaOngkir chosen but its own API call fails — must NOT silently
        // switch to OpenRoute (the konsumen never agreed to that price).
        Http::fake(['api.rajaongkir.com/*' => Http::response([], 500)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'rajaongkir',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(0, (float) $response->json('data.shipping_fee_amount'));
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'free']);
    }

    public function test_picking_an_inactive_shipping_method_is_rejected(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        // Only RajaOngkir active — OpenRoute is not.
        $this->configureRajaOngkir($agen);

        $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'openroute',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ])->assertStatus(422);
    }

    /* ---------------- Snapshot immutability ---------------- */

    public function test_shipping_config_change_never_alters_a_past_orders_snapshot(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureOpenRoute($agen);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 10000]]]], 200)]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ])->json('data.id');

        $this->assertEquals(20000, (float) Order::withoutGlobalScopes()->find($orderId)->shipping_fee_amount);

        // Admin changes the rate AFTER the order was placed.
        ShippingConfiguration::where('agent_id', $agen->id)->update(['price_per_km' => 99999]);

        $this->assertEquals(
            20000,
            (float) Order::withoutGlobalScopes()->find($orderId)->fresh()->shipping_fee_amount,
            'a later config change must never alter a historical order'
        );
    }
}
