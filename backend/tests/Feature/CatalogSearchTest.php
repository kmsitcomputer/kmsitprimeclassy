<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_search_filters_products_by_name(): void
    {
        Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 'name' => 'Black Forest Cake', 'slug' => 'bf-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active']);
        Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 'name' => 'Choco Chip Cookies', 'slug' => 'ccc-'.uniqid(), 'has_variations' => false, 'base_price' => 30000, 'weight_grams' => 200, 'status' => 'active']);

        $response = $this->getJson('/api/v1/products?search=Cake');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Black Forest Cake', $names);
        $this->assertNotContains('Choco Chip Cookies', $names);
    }

    public function test_sort_by_price_orders_products_including_variation_based_ones(): void
    {
        Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 'name' => 'Murah', 'slug' => 'murah-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active']);
        Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 'name' => 'Mahal', 'slug' => 'mahal-'.uniqid(), 'has_variations' => false, 'base_price' => 500000, 'weight_grams' => 1000, 'status' => 'active']);

        $asc = $this->getJson('/api/v1/products?sort=price_asc')->json('data');
        $this->assertSame('Murah', $asc[0]['name']);

        $desc = $this->getJson('/api/v1/products?sort=price_desc')->json('data');
        $this->assertSame('Mahal', $desc[0]['name']);
    }

    public function test_price_range_filter_uses_the_cheapest_variant_as_starting_price(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create(['name' => 'Cake Bertingkat', 'slug' => 'cb-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CB-S', 'price' => 50000, 'weight_grams' => 500, 'attributes' => ['Ukuran' => 'Kecil'],
        ])->assertCreated();
        $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CB-L', 'price' => 200000, 'weight_grams' => 2000, 'attributes' => ['Ukuran' => 'Besar'],
        ])->assertCreated();

        $withinRange = $this->getJson('/api/v1/products?min_price=40000&max_price=60000')->json('data');
        $this->assertContains('Cake Bertingkat', collect($withinRange)->pluck('name')->all());

        $tooExpensive = $this->getJson('/api/v1/products?min_price=300000')->json('data');
        $this->assertNotContains('Cake Bertingkat', collect($tooExpensive)->pluck('name')->all());
    }
}
