<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Regency;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->seed(ShippingCourierSeeder::class);
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
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
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
            ['config' => ['api_key' => $apiKey, 'profile' => 'driving-car']],
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
            ['config' => ['api_key' => 'test-key', 'api_version' => 'komerce_v2', 'origin_destination_id' => '4816', 'origin_label' => '-, BANDUNG, BANDUNG, JAWA BARAT, 40614', 'origin_search' => 'Kota Bandung', 'couriers' => $couriers]],
        );

        return $rajaOngkir->fresh();
    }

    private function fakeRajaOngkir(array $costs, int $costStatus = 200): void
    {
        Http::fake(function ($request) use ($costs, $costStatus) {
            if (str_contains($request->url(), '/destination/domestic-destination')) {
                return Http::response(['data' => [[
                    'id' => 9001, 'label' => 'KELURAHAN UJI COBA, KECAMATAN UJI COBA, UJI COBA, PROVINSI UJI COBA, 99999',
                    'province_name' => 'Provinsi Uji Coba', 'city_name' => 'Uji Coba',
                    'district_name' => 'Kecamatan Uji Coba', 'subdistrict_name' => 'Kelurahan Uji Coba', 'zip_code' => '99999',
                ]]], 200);
            }

            return Http::response(['data' => $costs], $costStatus);
        });
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
            ->assertJsonValidationErrors(['config.api_version', 'config.origin_destination_id', 'config.origin_label', 'config.origin_search', 'config.couriers']);
    }

    public function test_agent_can_search_validate_and_save_an_official_rajaongkir_origin_without_exposing_the_key(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $rajaOngkir = ShippingProvider::where('code', 'rajaongkir')->first();
        Http::fake(['rajaongkir.komerce.id/*' => Http::response(['data' => [[
            'id' => 4816,
            'label' => '-, BANDUNG, BANDUNG, JAWA BARAT, 40614',
            'province_name' => 'JAWA BARAT',
            'city_name' => 'BANDUNG',
            'district_name' => 'BANDUNG',
            'subdistrict_name' => null,
            'zip_code' => '40614',
        ]]], 200)]);

        $search = $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/destinations", [
            'search' => 'Kota Bandung',
            'api_key' => 'new-secret-key',
        ]);
        $search->assertOk()->assertJsonPath('data.0.id', '4816');
        $this->assertStringNotContainsString('new-secret-key', $search->getContent());

        $saved = $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$rajaOngkir->id}/config", [
            'config' => [
                'api_key' => 'new-secret-key',
                'api_version' => 'komerce_v2',
                'origin_destination_id' => '4816',
                'origin_label' => 'client value is not trusted',
                'origin_search' => 'Kota Bandung',
                'couriers' => ['jne', 'tiki'],
            ],
        ]);
        $saved->assertOk();
        $this->assertStringNotContainsString('new-secret-key', $saved->getContent());

        $config = AgentShippingProviderConfig::where('agent_id', $agen->id)->where('shipping_provider_id', $rajaOngkir->id)->firstOrFail()->config;
        $this->assertSame('4816', $config['origin_destination_id']);
        $this->assertSame('-, BANDUNG, BANDUNG, JAWA BARAT, 40614', $config['origin_label']);
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
            ->push(['routes' => [['summary' => ['distance' => 3000, 'duration' => 480]]]], 200)
            ->push(['routes' => [['summary' => ['distance' => 10000, 'duration' => 1200]]]], 200)]);

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
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/directions/driving-car/json')
            && $request['coordinates'][0] === [106.8166, -6.2]
            && $request['coordinates'][1] === [107.65, -6.9]
            && $request->hasHeader('Authorization', 'test-key'));
        $shipment = Shipment::where('order_id', $far->json('data.id'))->firstOrFail();
        $this->assertEquals(10000.0, $shipment->provider_meta['distance_meters']);
        $this->assertEquals(1200.0, $shipment->provider_meta['duration_seconds']);
        $this->assertSame('driving-car', $shipment->provider_meta['routing_profile']);
        $this->assertSame(107.65, $shipment->provider_meta['destination']['longitude']);
    }

    public function test_openroute_connection_test_is_server_side_and_sanitized(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $openRoute = $this->configureOpenRoute($agen, apiKey: 'ors-secret-key');
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'routes' => [['summary' => ['distance' => 12540, 'duration' => 1680]]],
        ], 200)]);

        $response = $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$openRoute->id}/test", [
            'destination_latitude' => -6.914744,
            'destination_longitude' => 107.609810,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.distance_km', 12.54)
            ->assertJsonPath('data.duration_seconds', 1680);
        $this->assertStringNotContainsString('ors-secret-key', $response->getContent());
    }

    public function test_client_cannot_manipulate_openroute_distance_or_shipping_fee(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureOpenRoute($agen, pricePerKm: 2000);
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'routes' => [['summary' => ['distance' => 10000, 'duration' => 1200]]],
        ], 200)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'openroute',
            'distance_km' => 0.01, 'shipping_fee' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertSame(20000.0, (float) $response->json('data.shipping_fee_amount'));
        $this->assertSame(10.0, (float) Shipment::where('order_id', $response->json('data.id'))->value('distance_km'));
    }

    public function test_openroute_http_failure_rejects_an_explicit_local_delivery(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureOpenRoute($agen);

        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'openroute', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
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

        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ongkos Kirim Ekonomis', 'cost' => 18000, 'etd' => '2-3'],
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 25000, 'etd' => '1-2'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(18000, (float) $response->json('data.shipping_fee_amount')); // cheapest of the two services
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'rajaongkir']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/calculate/domestic-cost')
            && (string) $request['origin'] === '4816'
            && (string) $request['destination'] === '9001'
            && (int) $request['weight'] === 500
            && $request['courier'] === 'jne');
        $meta = Shipment::where('order_id', $response->json('data.id'))->firstOrFail()->provider_meta;
        $this->assertSame('komerce_v2', $meta['api_version']);
        $this->assertSame('jne', $meta['courier']);
        $this->assertSame('OKE', $meta['service']);
        $this->assertSame(500, $meta['weight_grams']);
    }

    /* ---------------- Konsumen picks a specific courier/service under "Ekspedisi" ---------------- */

    public function test_courier_options_lists_every_courier_service_cheapest_first(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen, couriers: ['jne', 'tiki']);

        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ekonomis', 'cost' => 18000, 'etd' => '2-3'],
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 25000, 'etd' => '1-2'],
            ['name' => 'TIKI', 'code' => 'tiki', 'service' => 'ECO', 'description' => 'Economy', 'cost' => 15000, 'etd' => '3-4'],
        ]);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/courier-options', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertOk();
        $options = $response->json('data');
        $this->assertCount(3, $options);
        // Cheapest first: TIKI ECO (15000), JNE OKE (18000), JNE REG (25000).
        $this->assertSame(['tiki', 'jne', 'jne'], array_column($options, 'courier'));
        $this->assertEquals([15000, 18000, 25000], array_column($options, 'cost'));
    }

    public function test_order_uses_the_konsumens_specific_courier_pick_not_the_cheapest(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen);

        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ekonomis', 'cost' => 18000, 'etd' => '2-3'],
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 25000, 'etd' => '1-2'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'courier' => 'jne', 'service' => 'REG', ...$this->destination(),
        ]);

        $response->assertCreated();
        // Explicitly picked REG (25000), not the cheaper OKE (18000).
        $this->assertEquals(25000, (float) $response->json('data.shipping_fee_amount'));
    }

    public function test_order_is_rejected_when_the_picked_courier_service_is_no_longer_available(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen);

        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ekonomis', 'cost' => 18000, 'etd' => '2-3'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'courier' => 'jne', 'service' => 'YES', ...$this->destination(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Regression: an APP_KEY rotation (or a DB restored onto a different app
     * instance) leaves every existing AgentShippingProviderConfig.config row
     * permanently undecryptable (Laravel's encrypted cast, DecryptException
     * "The MAC is invalid.") — this must degrade like any other
     * misconfiguration (free shipping / next provider), never a raw 500.
     */
    public function test_undecryptable_config_rejects_shipping_instead_of_crashing_or_charging_free(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        $this->configureRajaOngkir($agen);

        // Simulate an APP_KEY change: overwrite the encrypted column with
        // ciphertext that cannot possibly decrypt under the current key,
        // bypassing the model's own encrypting cast.
        DB::table('agent_shipping_provider_configs')
            ->where('agent_id', $agen->id)
            ->update(['config' => 'not-a-valid-encrypted-payload']);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_undecryptable_config_makes_courier_options_a_clean_422_not_a_500(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->makeProduct($agen);
        $this->configureRajaOngkir($agen);

        DB::table('agent_shipping_provider_configs')
            ->where('agent_id', $agen->id)
            ->update(['config' => 'not-a-valid-encrypted-payload']);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/courier-options', [
            'items' => [['product_id' => $this->makeProduct($agen)->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertStatus(422);
    }

    public function test_rajaongkir_falls_back_to_openroute_when_destination_regency_has_no_mapping(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);
        // Deliberately no rajaongkir_city_id set on the test regency.

        $this->configureRajaOngkir($agen);
        $this->configureOpenRoute($agen);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 5000, 'duration' => 720]]]], 200)]);

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

        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 10000, 'duration' => 1200]]]], 200)]);

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

        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ekonomis', 'cost' => 18000, 'etd' => '2-3'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'rajaongkir',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('shipments', ['order_id' => $response->json('data.id'), 'shipping_provider_code' => 'rajaongkir']);
        $this->assertEquals(18000, (float) $response->json('data.shipping_fee_amount'));
    }

    public function test_picking_a_shipping_method_that_fails_rejects_the_order(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $this->activateBothProvidersForMethodChoice($agen);

        // RajaOngkir chosen but its own API call fails — must NOT silently
        // switch to OpenRoute (the konsumen never agreed to that price).
        $this->fakeRajaOngkir([], 500);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'shipping_method' => 'rajaongkir',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
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
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 10000, 'duration' => 1200]]]], 200)]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ])->json('data.id');

        $this->assertEquals(20000, (float) Order::withoutGlobalScopes()->find($orderId)->shipping_fee_amount);

        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $originSnapshot = $shipment->provider_meta['origin'];

        // Agent location and rate change AFTER the order was placed.
        ShippingConfiguration::where('agent_id', $agen->id)->update(['price_per_km' => 99999]);
        AgentProfile::where('user_id', $agen->id)->update(['latitude' => -7.0, 'longitude' => 108.0]);

        $this->assertEquals(
            20000,
            (float) Order::withoutGlobalScopes()->find($orderId)->fresh()->shipping_fee_amount,
            'a later config change must never alter a historical order'
        );
        $this->assertSame($originSnapshot, $shipment->fresh()->provider_meta['origin']);
    }
}
