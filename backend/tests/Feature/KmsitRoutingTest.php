<?php

namespace Tests\Feature;

use App\DataTransferObjects\ShippingQuoteContext;
use App\Exceptions\ApiException;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\AgentShippingProviderSetting;
use App\Models\Role;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Services\Shipping\KmsitDistanceCalculator;
use App\Services\Shipping\ShippingQuoteService;
use App\Support\EndpointGuard;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** KMSIT Routing Server (OSRM) as the transparent Kurir Toko fallback. All transports faked; no live server is contacted. */
class KmsitRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** KMSIT Routing Gateway v1 success body (openapi.yaml RouteResponse). */
    private const OSRM_OK = ['request_id' => 'req_01TEST', 'profile' => 'car', 'distance_m' => 10000.0, 'duration_s' => 1200.0, 'geometry' => ['type' => 'LineString', 'coordinates' => []], 'waypoints' => [], 'warnings' => []];

    private static function err(string $code): array
    {
        return ['error' => ['code' => $code, 'message' => 'x', 'request_id' => 'req_01TEST']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(ShippingProviderSeeder::class);
        Cache::flush();
        EndpointGuard::$resolver = fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34'];
        config(['services.kmsit.trusted_hosts' => []]);
    }

    protected function tearDown(): void
    {
        EndpointGuard::$resolver = null;
        parent::tearDown();
    }

    private function agent(string $kmsitUrl = 'https://route.kmsit.example', bool $kmsit = true, bool $ors = false, array $orsOverrides = []): User
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'T', 'address' => 'A', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $or = ShippingProvider::where('code', 'openroute')->first();
        ShippingConfiguration::create(['agent_id' => $agen->id, 'shipping_provider_id' => $or->id, 'price_per_km' => 2000, 'minimum_distance_km' => 3, 'minimum_charge' => 0, 'is_active' => true]);
        if ($ors) {
            $or->update(['is_active' => true]);
            AgentShippingProviderConfig::replaceConfig($agen->id, $or->id, ['api_key' => 'ors-key', 'profile' => 'driving-car', ...$orsOverrides]);
        }
        if ($kmsit) {
            $k = ShippingProvider::where('code', 'kmsit')->first();
            $k->update(['is_active' => true]);
            AgentShippingProviderConfig::replaceConfig($agen->id, $k->id, ['base_url' => $kmsitUrl, 'profile' => 'driving', 'token' => 'kmsit-secret-token']);
        }

        return $agen;
    }

    private function ctx(User $agen, ?string $preferred = 'openroute'): ShippingQuoteContext
    {
        return new ShippingQuoteContext(-6.2, 106.8166, -6.914744, 107.60981, null, null, 1000, 100000, $agen->id, $preferred);
    }

    private function orsFake(int $meters = 10000): array
    {
        return ['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => $meters, 'duration' => 900]]]], 200)];
    }

    // ---- provider defaults ------------------------------------------------------------------------------------

    public function test_kmsit_is_registered_inactive_and_reseeding_never_overwrites_an_active_state(): void
    {
        $this->assertFalse(ShippingProvider::where('code', 'kmsit')->value('is_active'));
        ShippingProvider::where('code', 'kmsit')->update(['is_active' => true]);
        ShippingProvider::where('code', 'rajaongkir')->update(['is_active' => true]);

        $this->seed(ShippingProviderSeeder::class);

        $this->assertTrue((bool) ShippingProvider::where('code', 'kmsit')->value('is_active'));
        $this->assertTrue((bool) ShippingProvider::where('code', 'rajaongkir')->value('is_active'));
    }

    // ---- OSRM calculator --------------------------------------------------------------------------------------

    public function test_gateway_request_follows_the_openapi_contract_and_returns_validated_km(): void
    {
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);

        $r = app(KmsitDistanceCalculator::class)->route(-6.2, 106.8166, -6.914744, 107.60981, ['base_url' => 'https://route.kmsit.example/', 'profile' => 'car', 'token' => 'the-key']);

        $this->assertSame(10.0, $r->distanceKm);
        $this->assertSame(1200.0, $r->durationSeconds);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return $req->method() === 'GET'
                && parse_url($req->url(), PHP_URL_PATH) === '/api/v1/routes'                       // gateway path, not native /route/v1
                && $q['profile'] === 'car'
                && $q['coordinates'] === '106.8166,-6.2;107.60981,-6.914744'                      // lon,lat;lon,lat
                && $req->header('X-API-Key') === ['the-key']                                      // contract auth header
                && ! $req->hasHeader('Authorization');                                            // not Bearer
        });
    }

    public function test_a_trailing_api_v1_in_the_base_url_is_never_doubled_and_motorcycle_is_supported(): void
    {
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);

        app(KmsitDistanceCalculator::class)->route(-6.2, 106.8, -6.9, 107.6, ['base_url' => 'https://route.kmsit.example/api/v1', 'profile' => 'motorcycle', 'token' => 'k']);

        Http::assertSent(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return parse_url($req->url(), PHP_URL_PATH) === '/api/v1/routes' && $q['profile'] === 'motorcycle';
        });
    }

    public function test_legacy_driving_profile_maps_to_car_and_unsupported_profiles_and_missing_key_never_hit_the_network(): void
    {
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);
        $calc = app(KmsitDistanceCalculator::class);
        $base = ['base_url' => 'https://route.kmsit.example', 'token' => 'k'];

        $this->assertSame('car', $calc->route(-6.2, 106.8, -6.9, 107.6, $base + ['profile' => 'driving'])->profile);

        foreach ([['profile' => 'bike'], ['profile' => 'car', 'token' => ''], ['profile' => 'car', 'token' => null]] as $bad) {
            try {
                $calc->route(-6.2, 106.8, -6.9, 107.6, $bad + $base);
                $this->fail('expected refusal');
            } catch (\App\Exceptions\KmsitRouteException $e) {
                $this->assertContains($e->reason, ['invalid_profile', 'missing_credential']);
            }
        }
        Http::assertSentCount(1);
    }

    /** @dataProvider badResponses */
    public function test_osrm_failures_never_produce_a_distance(array|string $body, int $status): void
    {
        Http::fake(['route.kmsit.example/*' => Http::response($body, $status)]);

        $this->expectException(ShippingQuoteException::class);
        app(KmsitDistanceCalculator::class)->route(-6.2, 106.8, -6.9, 107.6, ['base_url' => 'https://route.kmsit.example', 'profile' => 'car', 'token' => 'k']);
    }

    public static function badResponses(): array
    {
        return [
            'ROUTE_NOT_FOUND' => [self::err('ROUTE_NOT_FOUND'), 404],
            'VALIDATION_ERROR (outside coverage)' => [self::err('VALIDATION_ERROR'), 400],
            'UNAUTHENTICATED' => [self::err('UNAUTHENTICATED'), 401],
            'FORBIDDEN (no routes:read)' => [self::err('FORBIDDEN'), 403],
            'RATE_LIMITED' => [self::err('RATE_LIMITED'), 429],
            'UPSTREAM_TIMEOUT' => [self::err('UPSTREAM_TIMEOUT'), 504],
            'http 500' => [[], 500],
            'not json' => ['<html>', 200],
            '200 without distance' => [['request_id' => 'r', 'profile' => 'car'], 200],
            'native OSRM body is not the gateway contract' => [['code' => 'Ok', 'routes' => [['distance' => 100, 'duration' => 5]]], 200],
            'negative distance' => [['distance_m' => -1, 'duration_s' => 5], 200],
            'non numeric' => [['distance_m' => 'far', 'duration_s' => 5], 200],
            'missing duration' => [['distance_m' => 100], 200],
        ];
    }

    public function test_out_of_range_coordinates_are_rejected_before_any_request(): void
    {
        Http::fake();
        $this->expectException(ShippingQuoteException::class);
        try {
            app(KmsitDistanceCalculator::class)->route(-96.2, 106.8, -6.9, 107.6, ['base_url' => 'https://route.kmsit.example']);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---- SSRF -------------------------------------------------------------------------------------------------

    /** @dataProvider hostileEndpoints */
    public function test_ssrf_policy_rejects_hostile_endpoints(string $url): void
    {
        Http::fake();
        $this->expectException(ShippingQuoteException::class);
        try {
            app(KmsitDistanceCalculator::class)->route(-6.2, 106.8, -6.9, 107.6, ['base_url' => $url]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function hostileEndpoints(): array
    {
        return [
            'loopback' => ['https://127.0.0.1'], 'localhost name' => ['https://localhost'],
            'metadata' => ['https://169.254.169.254/latest'], 'private ip' => ['https://192.168.1.10'],
            'ipv6 loopback' => ['https://[::1]'], 'dns -> private' => ['https://api.internal.example'],
            'userinfo' => ['https://user:pw@route.kmsit.example'], 'ftp' => ['ftp://route.kmsit.example'],
            'file' => ['file:///etc/passwd'], 'plain http untrusted' => ['http://route.kmsit.example'],
            'query smuggling' => ['https://route.kmsit.example?x=1'], 'garbage' => ['not a url'],
        ];
    }

    public function test_dns_rebinding_candidate_with_any_private_answer_is_rejected(): void
    {
        EndpointGuard::$resolver = fn () => ['93.184.216.34', '10.1.1.1'];
        $this->expectException(ShippingQuoteException::class);
        EndpointGuard::vet('https://rebind.example');
    }

    public function test_only_an_operator_trusted_host_may_be_private_or_plain_http_and_metadata_is_never_reachable(): void
    {
        config(['services.kmsit.trusted_hosts' => ['osrm.internal']]);
        $v = EndpointGuard::vet('http://osrm.internal:5000');
        $this->assertSame('10.0.0.5', $v['ip']);
        $this->assertSame(5000, $v['port']);

        config(['services.kmsit.trusted_hosts' => ['169.254.169.254']]);
        $this->expectException(ShippingQuoteException::class);
        EndpointGuard::vet('http://169.254.169.254');
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->assertFalse(EndpointGuard::pin(['host' => 'route.kmsit.example', 'port' => 443, 'ip' => '93.184.216.34'])['allow_redirects']);
    }

    public function test_ambient_proxy_environment_can_never_carry_a_kmsit_request(): void
    {
        putenv('HTTPS_PROXY=http://proxy.invalid:3128');
        putenv('HTTP_PROXY=http://proxy.invalid:3128');
        try {
            $opts = EndpointGuard::pin(['host' => 'route.kmsit.example', 'port' => 443, 'ip' => '93.184.216.34']);

            $this->assertSame('', $opts['proxy']);                                   // overrides Guzzle's env proxy
            $this->assertFalse($opts['allow_redirects']);
            $this->assertSame('*', $opts['curl'][CURLOPT_NOPROXY]);                   // curl-level: no proxy for any host
            $this->assertSame(['route.kmsit.example:443:93.184.216.34'], $opts['curl'][CURLOPT_RESOLVE]); // pin still applied
            $this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYPEER, $opts['curl']);       // TLS verification untouched (default on)
        } finally {
            putenv('HTTPS_PROXY');
            putenv('HTTP_PROXY');
        }
    }

    // ---- failover matrix --------------------------------------------------------------------------------------

    public function test_ors_healthy_uses_ors_even_when_kmsit_is_configured(): void
    {
        $agen = $this->agent(ors: true);
        Http::fake($this->orsFake() + ['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);

        $q = app(ShippingQuoteService::class)->quote($this->ctx($agen));

        $this->assertSame('openrouteservice', $q->meta['routing_source']);
        $this->assertSame('openroute', $q->providerCode);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'route.kmsit.example'));
    }

    public function test_ors_failure_falls_back_to_kmsit_with_the_same_tariff(): void
    {
        $agen = $this->agent(ors: true);
        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500), 'route.kmsit.example/*' => Http::response(self::OSRM_OK)]);
        $viaKmsit = app(ShippingQuoteService::class)->quote($this->ctx($agen));

        Cache::flush();
        Http::fake($this->orsFake(10000));
        $viaOrs = app(ShippingQuoteService::class)->quote($this->ctx($agen));

        $this->assertSame('kmsit', $viaKmsit->meta['routing_source']);
        $this->assertSame(14000.0, $viaKmsit->cost); // (10 - 3) km * 2000
        $this->assertSame($viaOrs->cost, $viaKmsit->cost);
        $this->assertSame('openroute', $viaKmsit->providerCode);
    }

    public function test_ors_disabled_globally_or_per_agent_or_unconfigured_uses_kmsit(): void
    {
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);

        $disabled = $this->agent(ors: false); // openroute row inactive
        $this->assertSame('kmsit', app(ShippingQuoteService::class)->quote($this->ctx($disabled))->meta['routing_source']);

        $perAgent = $this->agent(ors: true);
        AgentShippingProviderSetting::create(['agent_id' => $perAgent->id, 'shipping_provider_id' => ShippingProvider::where('code', 'openroute')->value('id'), 'is_active' => false]);
        $this->assertSame('kmsit', app(ShippingQuoteService::class)->quote($this->ctx($perAgent))->meta['routing_source']);

        $unconfigured = $this->agent(ors: true);
        AgentShippingProviderConfig::where('agent_id', $unconfigured->id)->where('shipping_provider_id', ShippingProvider::where('code', 'openroute')->value('id'))->delete();
        $this->assertSame('kmsit', app(ShippingQuoteService::class)->quote($this->ctx($unconfigured))->meta['routing_source']);
    }

    public function test_both_unavailable_or_both_failed_fails_closed_with_no_fabricated_quote(): void
    {
        $failedBoth = $this->agent(ors: true);
        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500), 'route.kmsit.example/*' => Http::response(self::err('ROUTE_NOT_FOUND'), 404)]);
        try {
            app(ShippingQuoteService::class)->quote($this->ctx($failedBoth));
            $this->fail('expected fail closed');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $kmsitOff = $this->agent(kmsit: true, ors: true);
        ShippingProvider::where('code', 'kmsit')->update(['is_active' => false]);
        Http::fake(['api.openrouteservice.org/*' => Http::response([], 500)]);
        $this->expectException(ApiException::class);
        app(ShippingQuoteService::class)->quote($this->ctx($kmsitOff));
    }

    public function test_kmsit_is_never_a_customer_facing_method_but_keeps_kurir_toko_offerable(): void
    {
        $agen = $this->agent(ors: false);
        $methods = app(ShippingQuoteService::class)->availableShippingMethods($agen->id);

        $this->assertSame(['openroute', 'pickup'], array_column($methods, 'code'));
        $this->assertSame('Kurir Toko', $methods[0]['label']);
        $this->expectException(ApiException::class);
        app(ShippingQuoteService::class)->quote($this->ctx($agen, 'kmsit')); // not a selectable method
    }

    public function test_kmsit_inactive_leaves_existing_behaviour_untouched(): void
    {
        $agen = $this->agent(kmsit: false, ors: false);
        $this->assertSame(['pickup'], array_column(app(ShippingQuoteService::class)->availableShippingMethods($agen->id), 'code')); // no delivery method
        $this->assertSame(0.0, app(ShippingQuoteService::class)->quote($this->ctx($agen, null))->cost); // legacy: nothing active → free
    }

    public function test_free_shipping_override_still_applies_on_a_kmsit_route(): void
    {
        $agen = $this->agent();
        ShippingConfiguration::where('agent_id', $agen->id)->update(['free_shipping_enabled' => true, 'free_shipping_min_amount' => 50000]);
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);

        $q = app(ShippingQuoteService::class)->quote($this->ctx($agen));
        $this->assertSame(0.0, $q->cost);
        $this->assertSame('subtotal_threshold_met', $q->meta['rule']);
    }

    // ---- cache isolation --------------------------------------------------------------------------------------

    public function test_cache_is_isolated_per_agen_and_endpoint(): void
    {
        $a = $this->agent('https://a.kmsit.example');
        $b = $this->agent('https://b.kmsit.example');
        Http::fake([
            'a.kmsit.example/*' => Http::response(['distance_m' => 5000, 'duration_s' => 1]),
            'b.kmsit.example/*' => Http::response(['distance_m' => 20000, 'duration_s' => 1]),
        ]);

        $qa = app(ShippingQuoteService::class)->quote($this->ctx($a));
        $qb = app(ShippingQuoteService::class)->quote($this->ctx($b));
        $qa2 = app(ShippingQuoteService::class)->quote($this->ctx($a));

        $this->assertSame(5.0, $qa->distanceKm);
        $this->assertSame(20.0, $qb->distanceKm);
        $this->assertSame(5.0, $qa2->distanceKm);
        Http::assertSentCount(2); // second quote for A came from the kmsit: cache namespace
    }

    // ---- per-Agen configuration API ---------------------------------------------------------------------------

    private function putConfig(User $agen, array $config, array $extra = [])
    {
        $k = ShippingProvider::where('code', 'kmsit')->first();

        return $this->actingAs($agen)->putJson("/api/v1/agent/shipping-providers/{$k->id}/config", ['config' => $config] + $extra);
    }

    public function test_agen_saves_own_endpoint_encrypted_and_secrets_are_never_echoed_and_blank_token_keeps_the_old_one(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $r = $this->putConfig($agen, ['base_url' => 'https://route.kmsit.example/', 'profile' => 'car', 'token' => 'super-secret-token']);
        $r->assertOk();
        $this->assertStringNotContainsString('super-secret-token', $r->getContent());
        $this->assertStringNotContainsString('super-secret-token', json_encode(\DB::table('agent_shipping_provider_configs')->first()->config));

        $this->putConfig($agen, ['base_url' => 'https://route.kmsit.example', 'profile' => 'car', 'token' => ''])->assertOk();
        $kid = ShippingProvider::where('code', 'kmsit')->value('id');
        $this->assertSame('super-secret-token', AgentShippingProviderConfig::where('agent_id', $agen->id)->where('shipping_provider_id', $kid)->first()->config['token']);

        $list = $this->actingAs($agen)->getJson('/api/v1/agent/shipping-providers')->assertOk();
        $row = collect($list->json('data'))->firstWhere('code', 'kmsit');
        $this->assertTrue($row['has_token']);
        $this->assertSame('https://route.kmsit.example', $row['base_url']);
        $this->assertStringNotContainsString('super-secret-token', $list->getContent());
    }

    public function test_hostile_endpoint_is_rejected_on_save_and_nothing_is_stored(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->putConfig($agen, ['base_url' => 'https://169.254.169.254', 'profile' => 'car', 'token' => 'k'])->assertStatus(422);
        $this->putConfig($agen, ['base_url' => 'https://user:pw@route.kmsit.example', 'profile' => 'car', 'token' => 'k'])->assertStatus(422);
        // Gateway profiles only; and the Gateway needs a key, so a config without one is refused.
        $this->putConfig($agen, ['base_url' => 'https://route.kmsit.example', 'profile' => 'bike', 'token' => 'k'])->assertStatus(422);
        $this->putConfig($agen, ['base_url' => 'https://route.kmsit.example', 'profile' => 'car'])->assertStatus(422);
        $this->assertSame(0, AgentShippingProviderConfig::count());
    }

    public function test_one_agens_endpoint_never_leaks_to_another_agen(): void
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);
        $b = User::factory()->agen()->create();
        $b->update(['agent_id' => $b->id]);
        $this->putConfig($a, ['base_url' => 'https://a.kmsit.example', 'profile' => 'car', 'token' => 'k'])->assertOk();

        $row = collect($this->actingAs($b)->getJson('/api/v1/agent/shipping-providers')->json('data'))->firstWhere('code', 'kmsit');
        $this->assertNull($row['base_url']);
        $this->assertFalse($row['configured']);
    }

    public function test_pricing_can_be_saved_with_kmsit_into_the_shared_kurir_toko_rate(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->putConfig($agen, ['base_url' => 'https://route.kmsit.example', 'profile' => 'car', 'token' => 'k'], ['price_per_km' => 2500, 'minimum_distance_km' => 2, 'minimum_charge' => 5000])->assertOk();

        $rate = ShippingConfiguration::where('agent_id', $agen->id)->first();
        $this->assertSame(ShippingProvider::where('code', 'openroute')->value('id'), $rate->shipping_provider_id);
        $this->assertEquals(2500, $rate->price_per_km);
    }

    public function test_connection_test_is_server_side_sanitized_and_reports_failure_without_details(): void
    {
        $agen = $this->agent();
        $k = ShippingProvider::where('code', 'kmsit')->first();
        Http::fake(['route.kmsit.example/*' => Http::response(self::OSRM_OK)]);
        $ok = $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$k->id}/test", ['destination_latitude' => -6.9, 'destination_longitude' => 107.6])->assertOk();
        $this->assertTrue($ok->json('data.connected'));
        $this->assertStringNotContainsString('kmsit-secret-token', $ok->getContent());

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['route.kmsit.example/*' => Http::response([], 500)]);
        $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$k->id}/test", ['destination_latitude' => -6.9, 'destination_longitude' => 107.6])->assertStatus(422);
    }

    /** @dataProvider failureReasons */
    public function test_failed_connection_test_reports_only_a_safe_reason_never_url_token_or_body(int $status, array $body, string $reason): void
    {
        $agen = $this->agent();
        $k = ShippingProvider::where('code', 'kmsit')->first();
        Http::fake(['route.kmsit.example/*' => Http::response($body + ['secret_echo' => 'kmsit-secret-token'], $status)]);

        $r = $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$k->id}/test", ['destination_latitude' => -6.9, 'destination_longitude' => 107.6])->assertStatus(422);

        $this->assertSame($reason, $r->json('errors.reason.0'));
        $this->assertSame((string) $status, $r->json('errors.http_status.0'));
        $this->assertStringNotContainsString('kmsit-secret-token', $r->getContent());
        $this->assertStringNotContainsString('route.kmsit.example', $r->getContent());
    }

    public static function failureReasons(): array
    {
        return [
            'path not found' => [404, [], 'not_found'],
            'credential rejected' => [401, [], 'invalid_credential'],
            'forbidden' => [403, self::err('FORBIDDEN'), 'forbidden'],
            'no route' => [404, self::err('ROUTE_NOT_FOUND'), 'route_not_found'],
            'outside coverage' => [400, self::err('VALIDATION_ERROR'), 'validation_error'],
            'rate limited' => [429, self::err('RATE_LIMITED'), 'rate_limited'],
            'upstream error' => [502, [], 'upstream_unavailable'],
        ];
    }

    public function test_blocked_endpoint_reason_is_endpoint_blocked(): void
    {
        $agen = $this->agent('https://route.kmsit.example');
        $k = ShippingProvider::where('code', 'kmsit')->first();
        AgentShippingProviderConfig::replaceConfig($agen->id, $k->id, ['base_url' => 'https://169.254.169.254', 'profile' => 'car', 'token' => 'k']); // stale/hostile saved value
        Http::fake();

        $this->actingAs($agen)->postJson("/api/v1/agent/shipping-providers/{$k->id}/test", ['destination_latitude' => -6.9, 'destination_longitude' => 107.6])
            ->assertStatus(422)->assertJsonPath('errors.reason.0', 'endpoint_blocked');
        Http::assertNothingSent();
    }

    public function test_super_admin_sees_kmsit_global_switch_only_and_non_agen_cannot_configure(): void
    {
        $sa = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
        $agen = $this->agent();
        $list = $this->actingAs($sa)->getJson('/api/v1/admin/shipping-providers')->assertOk();
        $row = collect($list->json('data'))->firstWhere('code', 'kmsit');
        $this->assertSame(['id', 'code', 'name', 'is_active'], array_keys($row));
        $this->assertStringNotContainsString('route.kmsit.example', $list->getContent());

        $k = ShippingProvider::where('code', 'kmsit')->first();
        $this->actingAs($sa)->putJson("/api/v1/agent/shipping-providers/{$k->id}/config", ['config' => ['base_url' => 'https://x.example', 'profile' => 'car', 'token' => 'k']])->assertForbidden();
    }
}
