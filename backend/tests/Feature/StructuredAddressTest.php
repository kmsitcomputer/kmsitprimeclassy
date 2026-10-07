<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\District;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Province;
use App\Models\Regency;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IMP-002 — structured checkout address: canonical region ids persisted on
 * the order + server-side hierarchy validation.
 */
class StructuredAddressTest extends TestCase
{
    use RefreshDatabase;

    private array $b;

    private Village $village;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $product = Product::create(['sku' => 'SA-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        // Canonical regional chain with STABLE string ids (matching master widths:
        // province 2 / regency 4 / district 6 / village 10).
        $province = Province::create(['id' => '32', 'name' => 'Jawa Barat']);
        $regency = Regency::create(['id' => '3273', 'province_id' => $province->id, 'name' => 'Kota Bandung']);
        $district = District::create(['id' => '327301', 'regency_id' => $regency->id, 'name' => 'Coblong']);
        $this->village = Village::create(['id' => '32730101', 'district_id' => $district->id, 'name' => 'Dago']);

        $this->b = compact('agen', 'konsumen', 'product', 'province', 'regency', 'district');
    }

    private function placeOrder(array $extra = []): Order
    {
        $payload = array_merge([
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->village->id, 'latitude' => -6.9, 'longitude' => 107.6,
            'postal_code' => '40135',
        ], $extra);

        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_structured_region_ids_are_persisted_on_the_order(): void
    {
        $order = $this->placeOrder();

        $this->assertSame('32', $order->province_id);
        $this->assertSame('3273', $order->regency_id);
        $this->assertSame('327301', $order->district_id);
        $this->assertSame($this->village->id, $order->village_id);
        $this->assertSame('40135', $order->postal_code);
        // Legacy name snapshots still populated for backwards compatibility.
        $this->assertSame('Dago', $order->village_snapshot);
        $this->assertSame('Jawa Barat', $order->province_snapshot);
    }

    public function test_mismatched_district_hierarchy_is_rejected(): void
    {
        $otherDistrict = District::create(['id' => '327302', 'regency_id' => $this->b['regency']->id, 'name' => 'Sukajadi']);
        $village2 = Village::create(['id' => '32730201', 'district_id' => $otherDistrict->id, 'name' => 'Sukajadi']);

        // village_id belongs to a different district than the supplied district_id
        $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $village2->id, 'district_id' => $this->b['district']->id,
                'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertStatus(422);
    }

    public function test_address_id_compat_keeps_working(): void
    {
        $address = \App\Models\KonsumenAddress::create([
            'user_id' => $this->b['konsumen']->id,
            'recipient_name' => 'Buyer', 'phone' => '0811', 'address_line' => 'Jl. Lama',
            'province_id' => $this->b['province']->id, 'regency_id' => $this->b['regency']->id,
            'district_id' => $this->b['district']->id, 'village_id' => $this->village->id,
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'address_id' => $address->id,
            ])->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame($this->village->id, $order->village_id);
        $this->assertSame('Dago', $order->village_snapshot);
    }
}