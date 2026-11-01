<?php

namespace Tests\Feature;

use App\DataTransferObjects\ShippingQuoteContext;
use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Services\Shipping\ShippingQuoteService;
use App\Support\EndpointGuard;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Kurir Toko tariff is one per-Agen record, independent of ORS and KMSIT (they only supply distance). HTTP faked. */
class KurirTokoTariffTest extends TestCase
{
    use RefreshDatabase;

    private const GW_OK = ['request_id' => 'r', 'profile' => 'car', 'distance_m' => 10000.0, 'duration_s' => 900.0, 'geometry' => [], 'waypoints' => [], 'warnings' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(ShippingProviderSeeder::class);
        Cache::flush();
        EndpointGuard::$resolver = fn () => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        EndpointGuard::$resolver = null;
        parent::tearDown();
    }

    private function agen(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);
        AgentProfile::create(['user_id' => $a->id, 'store_name' => 'T', 'address' => 'A', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return $a;
    }

    private function anchor(): ShippingProvider
    {
        return ShippingProvider::where('code', 'openroute')->first();
    }

    private function enableKmsit(User $a): void
    {
        $k = ShippingProvider::where('code', 'kmsit')->first();
        $k->update(['is_active' => true]);
        AgentShippingProviderConfig::replaceConfig($a->id, $k->id, ['base_url' => 'https://route.kmsit.example', 'profile' => 'car', 'token' => 'k']);
    }

    private function enableOrs(User $a): void
    {
        $this->anchor()->update(['is_active' => true]);
        AgentShippingProviderConfig::replaceConfig($a->id, $this->anchor()->id, ['api_key' => 'ors-key', 'profile' => 'driving-car']);
    }

    private function tariff(User $a, array $over = []): array
    {
        return $over + ['price_per_km' => 2000, 'minimum_distance_km' => 3, 'minimum_charge' => 0];
    }

    private function quote(User $a): \App\DataTransferObjects\ShippingQuoteResult
    {
        return app(ShippingQuoteService::class)->quote(new ShippingQuoteContext(-6.2, 106.8166, -6.914744, 107.60981, null, null, 1000, 100000, $a->id, 'openroute'));
    }

    private function fakeBoth(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 10000, 'duration' => 900]]]]), 'route.kmsit.example/*' => Http::response(self::GW_OK)]);
    }

    public function test_tariff_can_be_saved_and_read_with_ors_off_and_unconfigured(): void
    {
        $a = $this->agen();
        $this->assertFalse((bool) $this->anchor()->is_active);                                      // ORS globally OFF, no ORS config

        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a, ['minimum_charge' => 5000]))->assertOk();
        $this->actingAs($a)->getJson('/api/v1/agent/shipping-tariff')->assertOk()
            ->assertJsonPath('data.tariff.price_per_km', 2000)->assertJsonPath('data.tariff.minimum_distance_km', 3)->assertJsonPath('data.tariff.minimum_charge', 5000);
        $this->assertSame(0, AgentShippingProviderConfig::count());                                 // saving the tariff touched no provider credentials
    }

    public function test_toggling_ors_globally_or_per_agen_or_saving_ors_credentials_never_changes_the_tariff(): void
    {
        $a = $this->agen();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a, ['free_shipping_enabled' => true, 'free_shipping_min_amount' => 250000]))->assertOk();
        $snapshot = fn () => ShippingConfiguration::where('agent_id', $a->id)->first()->only(['shipping_provider_id', 'price_per_km', 'minimum_distance_km', 'minimum_charge', 'free_shipping_enabled', 'free_shipping_min_amount', 'is_active']);
        $before = $snapshot();

        $this->actingAs($a)->patchJson("/api/v1/agent/shipping-providers/{$this->anchor()->id}/toggle")->assertOk();   // agent OFF
        $this->actingAs($a)->patchJson("/api/v1/agent/shipping-providers/{$this->anchor()->id}/toggle")->assertOk();   // agent ON
        $this->anchor()->update(['is_active' => false]);                                                                // global OFF
        $this->actingAs($a)->putJson("/api/v1/agent/shipping-providers/{$this->anchor()->id}/config", ['config' => ['api_key' => 'ors-key', 'profile' => 'driving-car']])->assertOk(); // ORS form WITHOUT tariff

        $this->assertEquals($before, $snapshot());
        $this->actingAs($a)->getJson('/api/v1/agent/shipping-tariff')->assertJsonPath('data.tariff.free_shipping_enabled', true);
    }

    public function test_updating_the_tariff_preserves_free_shipping_unless_sent(): void
    {
        $a = $this->agen();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a, ['free_shipping_enabled' => true, 'free_shipping_min_amount' => 99000]))->assertOk();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a, ['price_per_km' => 2500]))->assertOk();

        $r = ShippingConfiguration::where('agent_id', $a->id)->first();
        $this->assertEquals(2500, $r->price_per_km);
        $this->assertTrue($r->free_shipping_enabled);
        $this->assertEquals(99000, $r->free_shipping_min_amount);
        $this->assertSame(1, ShippingConfiguration::where('agent_id', $a->id)->count());          // one authoritative record per Agen
    }

    public function test_routing_matrix_uses_the_same_tariff_whatever_supplies_the_distance(): void
    {
        $a = $this->agen();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a))->assertOk();
        $this->enableOrs($a);
        $this->enableKmsit($a);
        $this->fakeBoth();

        $orsPrimary = $this->quote($a);                                                              // ORS ON + KMSIT ON
        $this->assertSame('openrouteservice', $orsPrimary->meta['routing_source']);

        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500), 'route.kmsit.example/*' => Http::response(self::GW_OK)]);
        $failover = $this->quote($a);                                                                // ORS fails + KMSIT ON

        Cache::flush();
        $this->anchor()->update(['is_active' => false]);                                             // ORS OFF + KMSIT ON
        $this->fakeBoth();
        $offKmsit = $this->quote($a);

        $this->assertSame('kmsit', $failover->meta['routing_source']);
        $this->assertSame('kmsit', $offKmsit->meta['routing_source']);
        $this->assertSame(14000.0, $orsPrimary->cost);                                               // (10 - 3) km x 2000
        $this->assertSame($orsPrimary->cost, $failover->cost);
        $this->assertSame($orsPrimary->cost, $offKmsit->cost);
    }

    public function test_ors_off_and_kmsit_off_fails_closed_even_though_the_tariff_exists(): void
    {
        $a = $this->agen();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a))->assertOk();
        Http::fake();

        $this->expectException(ApiException::class);
        try {
            $this->quote($a);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_no_tariff_means_fail_closed_never_a_free_or_guessed_quote(): void
    {
        $a = $this->agen();
        $this->enableKmsit($a);
        $this->fakeBoth();

        $this->expectException(ApiException::class);
        $this->quote($a);
    }

    public function test_legacy_tariff_row_created_before_this_change_is_read_and_used_unchanged(): void
    {
        $a = $this->agen();
        ShippingConfiguration::create(['agent_id' => $a->id, 'shipping_provider_id' => $this->anchor()->id, 'price_per_km' => 1500, 'minimum_distance_km' => 2, 'minimum_charge' => 0, 'is_active' => true]);
        $this->enableKmsit($a);
        $this->fakeBoth();

        $this->actingAs($a)->getJson('/api/v1/agent/shipping-tariff')->assertJsonPath('data.tariff.price_per_km', 1500);
        $this->assertSame(12000.0, $this->quote($a)->cost);                                          // (10 - 2) x 1500
        $row = collect($this->actingAs($a)->getJson('/api/v1/agent/shipping-providers')->json('data'))->firstWhere('code', 'kmsit');
        $this->assertSame(1500.0, (float) $row['pricing']['price_per_km']);                           // provider list still shows it
    }

    public function test_one_agens_tariff_is_never_visible_or_editable_by_another(): void
    {
        $a = $this->agen();
        $b = $this->agen();
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a, ['price_per_km' => 9999]))->assertOk();

        $this->actingAs($b)->getJson('/api/v1/agent/shipping-tariff')->assertOk()->assertJsonPath('data.tariff', null);
        $this->actingAs($b)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($b, ['price_per_km' => 1111, 'agent_id' => $a->id]))->assertOk(); // forged id ignored
        $this->assertEquals(9999, ShippingConfiguration::where('agent_id', $a->id)->value('price_per_km'));
        $this->assertEquals(1111, ShippingConfiguration::where('agent_id', $b->id)->value('price_per_km'));
        $this->assertStringNotContainsString('9999', json_encode($this->actingAs($b)->getJson('/api/v1/agent/shipping-providers')->json()));
    }

    public function test_validation_and_authorization_are_enforced_server_side(): void
    {
        $a = $this->agen();
        foreach ([['price_per_km' => -1], ['price_per_km' => 'abc'], ['minimum_distance_km' => -2], ['minimum_charge' => -5], ['price_per_km' => null]] as $bad) {
            $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $bad + $this->tariff($a))->assertStatus(422);
        }
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', ['minimum_distance_km' => 3])->assertStatus(422);
        $this->assertSame(0, ShippingConfiguration::count());

        foreach (['konsumen', 'sales', 'kurir', 'keuangan'] as $role) {
            $u = User::factory()->$role()->create();
            $this->actingAs($u)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($u))->assertForbidden();
            $this->actingAs($u)->getJson('/api/v1/agent/shipping-tariff')->assertForbidden();
        }
    }

    public function test_the_tariff_edit_is_audited_without_touching_secrets(): void
    {
        $a = $this->agen();
        $this->enableKmsit($a);
        $this->actingAs($a)->putJson('/api/v1/agent/shipping-tariff', $this->tariff($a))->assertOk();

        $log = \App\Models\ActivityLog::where('event', 'agent_shipping_tariff.updated')->first();
        $this->assertSame($a->id, $log->causer_id);
        $this->assertStringNotContainsString('token', json_encode($log->properties));
    }
}
