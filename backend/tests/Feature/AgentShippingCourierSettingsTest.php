<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Regency;
use App\Models\Role;
use App\Models\ShippingCourier;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Per-agen RajaOngkir courier enable/disable (Dashboard Agen > Pengiriman >
 * RajaOngkir > Kurir Aktif) — the provider-supported master list
 * (shipping_couriers) intersected with each agen's own saved config.couriers.
 */
class AgentShippingCourierSettingsTest extends TestCase
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

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        return compact('agen', 'konsumen', 'admin');
    }

    private function configureRajaOngkir(User $agen, array $couriers = ['jne']): ShippingProvider
    {
        $rajaOngkir = ShippingProvider::where('code', 'rajaongkir')->first();
        $rajaOngkir->update(['is_active' => true]);
        AgentShippingProviderConfig::updateOrCreate(
            ['agent_id' => $agen->id, 'shipping_provider_id' => $rajaOngkir->id],
            ['config' => [
                'api_key' => 'test-key', 'api_version' => 'komerce_v2', 'origin_destination_id' => '4816',
                'origin_label' => '-, BANDUNG, BANDUNG, JAWA BARAT, 40614', 'origin_search' => 'Kota Bandung',
                'couriers' => $couriers,
            ]],
        );

        return $rajaOngkir->fresh();
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

    private function fakeRajaOngkir(array $costs): void
    {
        Http::fake(function ($request) use ($costs) {
            if (str_contains($request->url(), '/destination/domestic-destination')) {
                return Http::response(['data' => [[
                    'id' => 9001, 'label' => 'KELURAHAN UJI COBA, KECAMATAN UJI COBA, UJI COBA, PROVINSI UJI COBA, 99999',
                    'province_name' => 'Provinsi Uji Coba', 'city_name' => 'Uji Coba',
                    'district_name' => 'Kecamatan Uji Coba', 'subdistrict_name' => 'Kelurahan Uji Coba', 'zip_code' => '99999',
                ]]], 200);
            }

            return Http::response(['data' => $costs], 200);
        });
    }

    /* ---------------- Dashboard: view + enable/disable ---------------- */

    public function test_agent_can_view_supported_rajaongkir_couriers(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen, couriers: ['jne', 'tiki']);

        $response = $this->actingAs($agen)->getJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers");

        $response->assertOk();
        $rows = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(17, $rows->count());
        $this->assertTrue($rows->firstWhere('code', 'jne')['enabled']);
        $this->assertTrue($rows->firstWhere('code', 'tiki')['enabled']);
        $this->assertFalse($rows->firstWhere('code', 'sicepat')['enabled']);
        $this->assertTrue($rows->every(fn ($r) => $r['supported'] === true));
    }

    public function test_agent_can_enable_a_courier(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen, couriers: ['jne']);

        $response = $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers", [
            'couriers' => ['jne', 'sicepat'],
        ]);

        $response->assertOk();
        $this->assertSame(['jne', 'sicepat'], $response->json('data.couriers'));
        $config = AgentShippingProviderConfig::where('agent_id', $agen->id)->where('shipping_provider_id', $provider->id)->firstOrFail()->config;
        $this->assertSame(['jne', 'sicepat'], $config['couriers']);
        // The rest of the config (api_key/origin) is untouched by a couriers-only save.
        $this->assertSame('test-key', $config['api_key']);
    }

    public function test_agent_can_disable_a_courier(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen, couriers: ['jne', 'sicepat', 'tiki']);

        $response = $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers", [
            'couriers' => ['jne', 'tiki'],
        ]);

        $response->assertOk();
        $config = AgentShippingProviderConfig::where('agent_id', $agen->id)->where('shipping_provider_id', $provider->id)->firstOrFail()->config;
        $this->assertEqualsCanonicalizing(['jne', 'tiki'], $config['couriers']);
        $this->assertNotContains('sicepat', $config['couriers']);
    }

    public function test_unsupported_courier_cannot_be_enabled(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen);

        $response = $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers", [
            'couriers' => ['jne', 'not-a-real-courier'],
        ]);

        $response->assertStatus(422);
        $config = AgentShippingProviderConfig::where('agent_id', $agen->id)->where('shipping_provider_id', $provider->id)->firstOrFail()->config;
        $this->assertSame(['jne'], $config['couriers']); // unchanged
    }

    public function test_agent_cannot_modify_another_agents_courier_settings(): void
    {
        ['agen' => $agenA] = $this->makeAgentBranch();
        ['agen' => $agenB] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agenA, couriers: ['jne']);
        $this->configureRajaOngkir($agenB, couriers: ['tiki']);

        // Agen B "modifies" the shared $provider row — but the controller
        // scopes by $request->user()->id, so this can only ever touch
        // Agen B's OWN config row, never Agen A's, regardless of which
        // provider id is in the URL.
        $this->actingAs($agenB)->putJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers", [
            'couriers' => ['sicepat'],
        ])->assertOk();

        $configA = AgentShippingProviderConfig::where('agent_id', $agenA->id)->where('shipping_provider_id', $provider->id)->firstOrFail()->config;
        $this->assertSame(['jne'], $configA['couriers'], "Agen A's own courier settings must be untouched by Agen B's request");
    }

    public function test_admin_cannot_access_agent_shipping_courier_settings(): void
    {
        ['agen' => $agen, 'admin' => $admin] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen);

        // Existing RBAC: shipping provider settings are agen-only (route
        // middleware role:agen) — admin has never had access to this, and
        // this feature doesn't newly grant it.
        $this->actingAs($admin)->getJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers")->assertStatus(403);
        $this->actingAs($admin)->putJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers", ['couriers' => ['jne']])->assertStatus(403);
    }

    public function test_rajaongkir_api_key_is_never_returned_to_the_frontend(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $provider = $this->configureRajaOngkir($agen, couriers: ['jne']);

        $couriers = $this->actingAs($agen)->getJson("/api/v1/agent/shipping-providers/{$provider->id}/couriers");
        $couriers->assertOk();
        $this->assertStringNotContainsString('test-key', $couriers->getContent());

        $index = $this->actingAs($agen)->getJson('/api/v1/agent/shipping-providers');
        $index->assertOk();
        $this->assertStringNotContainsString('test-key', $index->getContent());
    }

    /* ---------------- Super Admin master list ---------------- */

    public function test_super_admin_can_manage_the_supported_courier_master_list(): void
    {
        $superAdmin = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/shipping-couriers')->assertOk();

        $create = $this->actingAs($superAdmin)->postJson('/api/v1/admin/shipping-couriers', [
            'code' => 'idexpress', 'name' => 'ID Express',
        ]);
        $create->assertCreated();
        $this->assertDatabaseHas('shipping_couriers', ['code' => 'idexpress', 'is_active' => true]);

        $id = $create->json('data.id');
        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/shipping-couriers/{$id}/toggle")->assertOk();
        $this->assertDatabaseHas('shipping_couriers', ['code' => 'idexpress', 'is_active' => false]);
    }

    public function test_only_super_admin_can_manage_the_supported_courier_master_list(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();

        $this->actingAs($agen)->getJson('/api/v1/admin/shipping-couriers')->assertStatus(403);
        $this->actingAs($agen)->postJson('/api/v1/admin/shipping-couriers', ['code' => 'x', 'name' => 'X'])->assertStatus(403);
    }

    /* ---------------- Checkout must respect the enabled set ---------------- */

    public function test_disabled_courier_does_not_appear_in_checkout_options(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        // Only jne enabled — sicepat is provider-supported but not enabled by this agen.
        $this->configureRajaOngkir($agen, couriers: ['jne']);
        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 20000, 'etd' => '1-2'],
        ]);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/courier-options', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertOk();
        $codes = array_column($response->json('data'), 'courier');
        $this->assertSame(['jne'], $codes);
        $this->assertNotContains('sicepat', $codes);

        // The disabled courier's code was never even sent to Komerce.
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/calculate/domestic-cost')
            || $request['courier'] === 'jne');
    }

    public function test_disabled_courier_cannot_be_submitted_directly_at_order_creation(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        // Agen only enabled jne — sicepat is provider-supported but disabled.
        $this->configureRajaOngkir($agen, couriers: ['jne']);
        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 20000, 'etd' => '1-2'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'courier' => 'sicepat', 'service' => 'REG', ...$this->destination(),
        ]);

        // A courier the agen disabled is never silently substituted for —
        // an explicit courier pick that turns out unavailable rejects the
        // order outright (422) rather than using a courier/price the
        // konsumen never actually agreed to.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('orders', ['konsumen_id' => $konsumen->id]);
    }

    public function test_a_courier_the_agent_deactivated_globally_is_excluded_even_from_an_old_saved_config(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        // Agen's saved config still lists 'jne' — but Super Admin has since
        // deactivated it in the provider-supported master list. Defense in
        // depth: RajaOngkirProvider must exclude it even though it's still
        // sitting in the agen's own old config.couriers array.
        $this->configureRajaOngkir($agen, couriers: ['jne']);
        ShippingCourier::where('code', 'jne')->update(['is_active' => false]);

        $response = $this->actingAs($konsumen)->postJson('/api/v1/checkout/courier-options', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertStatus(422);
    }

    public function test_enabled_courier_can_still_calculate_shipping_cost(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, weightGrams: 500);
        $villageId = $this->seedTestVillage();
        $regencyId = Village::find($villageId)->district->regency_id;
        Regency::where('id', $regencyId)->update(['rajaongkir_city_id' => '501']);

        $this->configureRajaOngkir($agen, couriers: ['jne']);
        $this->fakeRajaOngkir([
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 22000, 'etd' => '1-2'],
        ]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->destination(),
        ]);

        $response->assertCreated();
        $this->assertEquals(22000, (float) $response->json('data.shipping_fee_amount'));
    }
}
