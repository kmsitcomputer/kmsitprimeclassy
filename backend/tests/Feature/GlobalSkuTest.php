<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GlobalSkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function simple(array $extra = []): array
    {
        return array_merge(['name' => 'Simple', 'has_variations' => false, 'base_price' => 100, 'weight_grams' => 10], $extra);
    }

    public function test_simple_product_requires_sku_and_preserves_it_on_update(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->postJson('/api/v1/products', $this->simple())->assertUnprocessable();
        $r = $this->postJson('/api/v1/products', $this->simple(['sku' => 'ONE']))->assertCreated();
        $id = $r->json('data.id');
        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Updated'])->assertOk()->assertJsonPath('data.sku', 'ONE');
        $this->patchJson('/api/v1/products/'.$id, ['sku' => null])->assertUnprocessable();
        $this->postJson('/api/v1/products', $this->simple(['sku' => 'ONE']))->assertUnprocessable();
    }

    public function test_global_namespace_rejects_both_cross_table_collisions(): void
    {
        $parent = Product::create(['name' => 'Variants', 'slug' => 'variants', 'has_variations' => true]);
        $variant = ProductVariation::create(['product_id' => $parent->id, 'sku' => 'VARIANT-ONE', 'price' => 10, 'weight_grams' => 1]);
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->postJson('/api/v1/products', $this->simple(['sku' => $variant->sku]))->assertUnprocessable();
        $this->postJson('/api/v1/products', $this->simple(['sku' => 'SIMPLE-ONE']))->assertCreated();
        foreach (['SIMPLE-ONE', 'VARIANT-ONE'] as $sku) {
            $this->postJson('/api/v1/products/'.$parent->id.'/variations', ['sku' => $sku, 'price' => 10, 'weight_grams' => 1, 'attributes' => ['Size' => 'Small']])->assertUnprocessable();
        }
    }

    public function test_model_write_cannot_bypass_global_namespace(): void
    {
        Product::create($this->simple(['slug' => 'a', 'sku' => 'MODEL-SKU']));
        $parent = Product::create(['name' => 'Variants', 'slug' => 'b', 'has_variations' => true]);
        $this->expectException(ValidationException::class);
        ProductVariation::create(['product_id' => $parent->id, 'sku' => 'MODEL-SKU', 'price' => 10, 'weight_grams' => 1]);
    }

    public function test_backfill_is_idempotent_and_preserves_existing_sku(): void
    {
        $id = DB::table('products')->insertGetId(['name' => 'Legacy', 'slug' => 'legacy', 'has_variations' => false, 'status' => 'draft']);
        $this->artisan('products:backfill-sku')->assertSuccessful();
        $this->assertDatabaseHas('products', ['id' => $id, 'sku' => null]);
        $this->artisan('products:backfill-sku', ['--apply' => true])->assertSuccessful();
        $sku = Product::find($id)->sku;
        $this->artisan('products:backfill-sku', ['--apply' => true])->assertSuccessful();
        $this->assertSame($sku, Product::find($id)->sku);
    }
}
