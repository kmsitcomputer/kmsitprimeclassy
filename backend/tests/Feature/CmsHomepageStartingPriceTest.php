<?php

namespace Tests\Feature;

use App\Models\CmsHomepageBlock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the homepage "Produk" block rendered "mulai —" for every product with variations, because the
 * public homepage feed only carried `base_price` (null for variation products). The feed now also carries
 * `starting_price` with the SAME eligibility as the public catalog (ProductController): the cheapest ACTIVE
 * variation, never an inactive/soft-deleted one, never a made-up number.
 */
class CmsHomepageStartingPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function product(string $name, bool $variations, ?int $basePrice, int $categoryId): Product
    {
        // Domain rule (HasGlobalSku): the SKU lives on the variations, a variation product carries none itself.
        return Product::create(array_filter([
            'sku' => $variations ? null : 'SP-'.Str::uuid(), 'category_id' => $categoryId, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => $variations, 'base_price' => $variations ? null : $basePrice, 'weight_grams' => $variations ? null : 500, 'status' => 'active',
        ], fn ($v, $k) => $v !== null || in_array($k, ['base_price'], true), ARRAY_FILTER_USE_BOTH));
    }

    private function variation(Product $product, int $price, bool $active = true): ProductVariation
    {
        return ProductVariation::create(['product_id' => $product->id, 'sku' => 'V-'.Str::uuid(), 'price' => $price, 'weight_grams' => 500, 'is_active' => $active, 'sort_order' => 0]);
    }

    private function feed(int $categoryId): array
    {
        CmsHomepageBlock::create(['type' => 'product', 'content' => ['category_id' => $categoryId, 'limit' => 24], 'sort_order' => 0, 'is_active' => true]);

        return collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.0.content.products'))->keyBy('name')->all();
    }

    public function test_a_variation_product_exposes_its_cheapest_active_variation_as_the_starting_price(): void
    {
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'is_active' => true]);
        $kastangel = $this->product('Kastangel', true, null, $cat->id);
        $this->variation($kastangel, 110000);
        $this->variation($kastangel, 95000);
        $this->variation($kastangel, 55000);

        $products = $this->feed($cat->id);

        $this->assertTrue($products['Kastangel']['has_variations']);
        $this->assertNull($products['Kastangel']['base_price']); // unchanged: still no base price
        $this->assertEquals(55000, $products['Kastangel']['starting_price']);
    }

    public function test_inactive_and_deleted_variations_never_set_the_starting_price(): void
    {
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'is_active' => true]);
        $product = $this->product('Putri Salju', true, null, $cat->id);
        $this->variation($product, 10000, active: false); // cheapest, but not sellable
        $deleted = $this->variation($product, 20000);
        $deleted->delete();                                // soft-deleted
        $this->variation($product, 70000);
        $this->variation($product, 90000);

        $this->assertEquals(70000, $this->feed($cat->id)['Putri Salju']['starting_price']);
    }

    public function test_a_variation_product_without_any_active_variation_has_no_price_instead_of_a_fake_one(): void
    {
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'is_active' => true]);
        $none = $this->product('Tanpa Variasi Aktif', true, null, $cat->id);
        $this->variation($none, 30000, active: false);
        $this->product('Tanpa Variasi Sama Sekali', true, null, $cat->id);

        $products = $this->feed($cat->id);

        $this->assertNull($products['Tanpa Variasi Aktif']['starting_price']);
        $this->assertNull($products['Tanpa Variasi Sama Sekali']['starting_price']);
    }

    public function test_a_simple_product_keeps_its_base_price_as_the_starting_price(): void
    {
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'is_active' => true]);
        $this->product('Black Forest', false, 100000, $cat->id);

        $products = $this->feed($cat->id);

        $this->assertEquals(100000, $products['Black Forest']['base_price']);
        $this->assertEquals(100000, $products['Black Forest']['starting_price']);
    }

    public function test_the_homepage_feed_agrees_with_the_public_catalog_starting_price(): void
    {
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'is_active' => true]);
        $product = $this->product('Almond Crispy', true, null, $cat->id);
        $this->variation($product, 65000);
        $this->variation($product, 48000);
        $this->variation($product, 12000, active: false);

        $home = (float) $this->feed($cat->id)['Almond Crispy']['starting_price'];

        $catalog = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->firstWhere('name', 'Almond Crispy');
        $catalogMin = min(array_map(fn ($v) => (float) $v['price'], $catalog['variations']));

        $this->assertSame($catalogMin, $home);
        $this->assertSame(48000.0, $home);
    }
}
