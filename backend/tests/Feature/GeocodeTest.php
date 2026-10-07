<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Regency;
use App\Models\User;
use App\Models\Village;
use App\Services\Geocode\GeocodeService;
use App\Services\Geocode\NominatimReverseGeocoder;
use App\Services\Geocode\NoopReverseGeocoder;
use App\Services\Geocode\ReverseGeocoder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IMP-002 — GPS "Gunakan Lokasi Saya" normalization + regional matching.
 */
class GeocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geocode.provider' => 'nominatim', 'services.geocode.cache_store' => 'array']);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
    }

    private function responseAddress(): array
    {
        return ['place_id' => 999999, 'address' => [
            'country_code' => 'id', 'state' => 'Jawa Barat', 'city' => 'Kota Bandung',
            'city_district' => 'Kecamatan Coblong', 'suburb' => 'Kelurahan Dago', 'postcode' => '40135',
        ]];
    }

    private function seedRegions(): void
    {
        Province::create(['id' => '32', 'name' => 'Jawa Barat']);
        Regency::create(['id' => '3273', 'province_id' => '32', 'name' => 'Kota Bandung']);
        District::create(['id' => '327301', 'regency_id' => '3273', 'name' => 'Coblong']);
        Village::create(['id' => '3273010001', 'district_id' => '327301', 'name' => 'Dago']);
    }

    public function test_default_adapter_binding_and_configured_http_request(): void
    {
        config(['services.geocode.base_url' => 'https://geo.example.test', 'services.geocode.timeout' => 3,
            'services.geocode.connect_timeout' => 1, 'services.geocode.user_agent' => 'PrimeClassy/1.0 (+https://app.example.test)']);
        $this->assertInstanceOf(NominatimReverseGeocoder::class, app(ReverseGeocoder::class));
        $sentOptions = [];
        Http::fake(function ($request, $options) use (&$sentOptions) {
            $sentOptions = $options;

            return Http::response($this->responseAddress());
        });
        $result = app(ReverseGeocoder::class)->reverse(-6.9, 107.6);
        $this->assertEquals(3, $sentOptions['timeout']);
        $this->assertEquals(1, $sentOptions['connect_timeout']);
        $this->assertFalse($sentOptions['allow_redirects']);
        $this->assertSame('Kelurahan Dago', $result['village']);
        $this->assertSame('40135', $result['postal_code']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.example.test/reverse?')
            && $request->hasHeader('User-Agent', 'PrimeClassy/1.0 (+https://app.example.test)')
            && $request['format'] === 'jsonv2' && $request['accept-language'] === 'id'
            && (float) $request['lat'] === -6.9 && (float) $request['lon'] === 107.6);
        config(['services.geocode.provider' => 'disabled']);
        $this->assertInstanceOf(NoopReverseGeocoder::class, app(ReverseGeocoder::class));
        $this->assertNull(app(ReverseGeocoder::class)->reverse(-6.9, 107.6));
        Http::assertSentCount(1);
    }

    public function test_live_adapter_matches_internal_chain_and_endpoint_returns_only_master_ids(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seedRegions();
        // Same village name elsewhere must not mask the correctly scoped chain.
        Province::create(['id' => '33', 'name' => 'Jawa Tengah']);
        Regency::create(['id' => '3373', 'province_id' => '33', 'name' => 'Kota Lain']);
        District::create(['id' => '337301', 'regency_id' => '3373', 'name' => 'Coblong']);
        Village::create(['id' => '3373010001', 'district_id' => '337301', 'name' => 'Dago']);
        Http::fake(['*' => Http::response($this->responseAddress())]);
        $this->actingAs(User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]))->postJson('/api/v1/checkout/geocode', ['latitude' => -6.9, 'longitude' => 107.6])
            ->assertOk()->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.result.province_id', '32')->assertJsonPath('data.result.regency_id', '3273')
            ->assertJsonPath('data.result.district_id', '327301')->assertJsonPath('data.result.village_id', '3273010001')
            ->assertJsonPath('data.result.postal_code', '40135')->assertJsonMissing(['place_id' => 999999]);
    }

    public static function invalidResponses(): array
    {
        return [
            'provider failure' => [503, ['error' => 'unavailable']],
            'rate limit' => [429, ['error' => 'limited']],
            'no match' => [200, ['error' => 'Unable to geocode']],
            'malformed JSON' => [200, '{'],
            'no address' => [200, ['place_id' => 123]],
            'array label' => [200, ['address' => ['country_code' => 'id', 'state' => [], 'city' => 'Bandung', 'village' => 'Dago']]],
            'foreign country' => [200, ['address' => ['country_code' => 'gb', 'state' => 'Jawa Barat', 'city' => 'Kota Bandung', 'village' => 'Dago']]],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_provider_errors_and_malformed_payloads_preserve_manual_entry(int $status, mixed $body): void
    {
        $this->seed(RoleSeeder::class);
        Http::fake(['*' => Http::response($body, $status)]);
        $this->actingAs(User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]))->postJson('/api/v1/checkout/geocode', ['latitude' => -6.9, 'longitude' => 107.6])
            ->assertOk()->assertJsonPath('data.matched', false)->assertJsonPath('data.result', null);
        Http::assertSentCount(1);
    }

    public function test_timeout_or_network_exception_falls_back_without_retry(): void
    {
        $this->seed(RoleSeeder::class);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->actingAs(User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]))->postJson('/api/v1/checkout/geocode', ['latitude' => -6.9, 'longitude' => 107.6])
            ->assertOk()->assertJsonPath('data.matched', false)->assertJsonPath('data.result', null);
    }

    public function test_cache_and_global_rate_limit_prevent_bursts_across_instances(): void
    {
        Http::fake(['*' => Http::response($this->responseAddress())]);
        $first = (new NominatimReverseGeocoder)->reverse(-6.9, 107.6);
        $this->assertSame($first, (new NominatimReverseGeocoder)->reverse(-6.9, 107.6));
        $this->assertNull((new NominatimReverseGeocoder)->reverse(-7.0, 108.0));
        Http::assertSentCount(1);
        $prefix = 'geocode:nominatim:'.hash('sha256', rtrim(config('services.geocode.base_url'), '/'));
        Cache::store('array')->forget($prefix.':last-request');
        $held = Cache::store('array')->lock($prefix.':lock', 15);
        $this->assertTrue($held->get());
        try {
            $this->assertNull((new NominatimReverseGeocoder)->reverse(-7.1, 108.1));
            Http::assertSentCount(1);
        } finally {
            $held->release();
        }
    }

    public function test_no_master_match_and_ambiguous_master_match_are_manual_fallback(): void
    {
        Http::fake(['*' => Http::response($this->responseAddress())]);
        $this->assertNull(app(GeocodeService::class)->locate(-6.9, 107.6));
        $this->seedRegions();
        $this->assertNotNull(app(GeocodeService::class)->locate(-6.9, 107.6));
        Village::create(['id' => '3273010002', 'district_id' => '327301', 'name' => 'Dago']);
        $this->assertNull(app(GeocodeService::class)->locate(-6.9, 107.6));
    }

    public function test_invalid_coordinates_are_rejected_before_provider_call(): void
    {
        $this->seed(RoleSeeder::class);
        Http::fake();
        $this->actingAs(User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]))->postJson('/api/v1/checkout/geocode', ['latitude' => 91, 'longitude' => 107.6])->assertUnprocessable();
        $this->assertNull(app(ReverseGeocoder::class)->reverse(INF, 107.6));
        Http::assertNothingSent();
    }

    public function test_provider_result_matches_canonical_region_master(): void
    {
        $province = Province::create(['id' => '32', 'name' => 'Jawa Barat']);
        $regency = Regency::create(['id' => '3273', 'province_id' => $province->id, 'name' => 'Kota Bandung']);
        $district = District::create(['id' => '327301', 'regency_id' => $regency->id, 'name' => 'Coblong']);
        Village::create(['id' => '32730101', 'district_id' => $district->id, 'name' => 'Dago']);

        $stub = new class implements ReverseGeocoder
        {
            public function reverse(float $latitude, float $longitude): ?array
            {
                return ['province' => 'Jawa Barat', 'regency' => 'Kota Bandung', 'district' => 'Coblong', 'village' => 'Dago', 'postal_code' => '40135'];
            }
        };

        $match = (new GeocodeService($stub))->locate(-6.9, 107.6);
        $this->assertNotNull($match);
        $this->assertSame('32730101', $match['village_id']);
        $this->assertSame('40135', $match['postal_code']);
        $this->assertSame('Coblong', $match['district_name']);
    }

    public function test_conflicting_provider_labels_are_rejected_not_stored(): void
    {
        $province = Province::create(['id' => '32', 'name' => 'Jawa Barat']);
        $regency = Regency::create(['id' => '3273', 'province_id' => $province->id, 'name' => 'Kota Bandung']);
        $district = District::create(['id' => '327301', 'regency_id' => $regency->id, 'name' => 'Coblong']);
        Village::create(['id' => '32730101', 'district_id' => $district->id, 'name' => 'Dago']);

        // Provider claims a DIFFERENT regency — the match must fail (never
        // store the external text as canonical).
        $stub = new class implements ReverseGeocoder
        {
            public function reverse(float $latitude, float $longitude): ?array
            {
                return ['province' => 'Jawa Barat', 'regency' => 'Kota Bogor', 'district' => 'Coblong', 'village' => 'Dago'];
            }
        };

        $this->assertNull((new GeocodeService($stub))->locate(-6.9, 107.6));
    }
}
