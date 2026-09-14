<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShippingProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Checkout coordinate handling (Google Maps picker / "Gunakan Lokasi
 * Sekarang" both ultimately just set latitude/longitude on the same
 * destination form) — backend validation, order snapshot, and OpenRoute
 * actually using the submitted coordinate (never trusting the frontend
 * beyond format).
 */
class CheckoutCoordinateTest extends TestCase
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

    private function makeProduct(User $agen): Product
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'QA Cake', 'slug' => 'qa-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        return $product;
    }

    private function payload(Product $product, float $lat, float $lng): array
    {
        return [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    public function test_checkout_accepts_valid_coordinates(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($product, -6.9467471, 107.6737923));

        $response->assertCreated();
    }

    #[DataProvider('invalidLatitudes')]
    public function test_checkout_rejects_invalid_latitude(float $lat): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($product, $lat, 107.6));

        $response->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    public static function invalidLatitudes(): array
    {
        return [[90.0001], [-90.0001], [200.0], [-200.0]];
    }

    #[DataProvider('invalidLongitudes')]
    public function test_checkout_rejects_invalid_longitude(float $lng): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($product, -6.9, $lng));

        $response->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public static function invalidLongitudes(): array
    {
        return [[180.0001], [-180.0001], [400.0], [-400.0]];
    }

    public function test_order_stores_shipping_coordinate_snapshot(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($product, -6.9467471, 107.6737923));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'latitude_snapshot' => -6.9467471,
            'longitude_snapshot' => 107.6737923,
        ]);
    }

    public function test_openroute_uses_the_coordinates_submitted_at_checkout(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen);

        $provider = ShippingProvider::where('code', 'openroute')->first();
        $provider->update(['is_active' => true]);
        AgentShippingProviderConfig::updateOrCreate(
            ['agent_id' => $agen->id, 'shipping_provider_id' => $provider->id],
            ['config' => ['api_key' => 'test-key', 'profile' => 'driving-car']],
        );
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'shipping_provider_id' => $provider->id,
            'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0,
            'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        $pickedLat = -6.9467471;
        $pickedLng = 107.6737923;

        Http::fake(['*/v2/directions/*' => Http::response([
            'routes' => [['summary' => ['distance' => 12345, 'duration' => 600]]],
        ], 200)]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($product, $pickedLat, $pickedLng));

        $response->assertCreated();

        // [longitude, latitude] order, and the EXACT map-picked coordinate —
        // never a stale/default one — reaches the ORS request.
        Http::assertSent(function ($request) use ($pickedLat, $pickedLng) {
            if (! str_contains($request->url(), '/v2/directions/')) {
                return false;
            }
            $coords = $request->data()['coordinates'][1] ?? null;

            return $coords === [$pickedLng, $pickedLat];
        });
    }
}
