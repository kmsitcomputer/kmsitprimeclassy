<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationAttribute;
use App\Models\ProductVariationAttributeOption;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class ProductSystemTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        return compact('agen', 'korsal', 'sales', 'konsumen', 'admin');
    }

    /* ---------------------------------------------------------------
     * Category / Product / Variation / Image CRUD
     * ------------------------------------------------------------- */

    public function test_only_super_admin_manages_categories_but_agen_can_also_manage_products(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);

        // Category taxonomy stays super_admin-only.
        $this->actingAs($agen)->postJson('/api/v1/categories', ['name' => 'Kue'])->assertStatus(403);

        $categoryResponse = $this->actingAs($superAdmin)->postJson('/api/v1/categories', ['name' => 'Kue']);
        $categoryResponse->assertCreated();

        $productResponse = $this->actingAs($superAdmin)->postJson('/api/v1/products', ['sku' => 'TEST-'.(string) Str::uuid(),
            'category_id' => $categoryResponse->json('data.id'),
            'name' => 'Black Forest', 'has_variations' => false,
            'base_price' => 150000, 'weight_grams' => 1000,
        ]);
        $productResponse->assertCreated();
        $this->assertSame('draft', $productResponse->json('data.status'));

        // Product catalog is shared/global (no agent_id on products) — an agen
        // may create AND edit any product, including one super_admin made,
        // by deliberate design decision (never limited to "their own").
        $agenCreated = $this->actingAs($agen)->postJson('/api/v1/products', ['sku' => 'TEST-'.(string) Str::uuid(),
            'name' => 'Kue Lain', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 500,
        ]);
        $agenCreated->assertCreated();

        $this->actingAs($agen)->patchJson("/api/v1/products/{$productResponse->json('data.id')}", [
            'status' => 'active',
        ])->assertOk();

        // Roles below agen still cannot manage the catalog at all.
        $this->actingAs($sales)->postJson('/api/v1/products', ['sku' => 'TEST-'.(string) Str::uuid(),
            'name' => 'Kue Sales', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 500,
        ])->assertStatus(403);
    }

    /**
     * A variation product must never carry its own base_price/weight_grams
     * — those live per-ProductVariation instead. This must hold both on
     * create and on update (the product's stored has_variations, not the
     * update payload, decides — since has_variations is immutable post-creation).
     */
    public function test_a_product_with_variations_enabled_may_never_carry_its_own_base_price_or_weight(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->postJson('/api/v1/products', [
            'name' => 'Kue Varian', 'has_variations' => true, 'base_price' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors('base_price');

        $product = Product::create(['name' => 'Kue Varian 2', 'slug' => 'kue-varian-2-'.uniqid(), 'has_variations' => true, 'status' => 'active']);

        $this->actingAs($superAdmin)->patchJson("/api/v1/products/{$product->id}", [
            'base_price' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('base_price');
    }

    public function test_variation_creation_reuses_attribute_and_option_rows_across_variations(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create([
            'name' => 'Cake Coklat', 'slug' => 'cake-coklat-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);

        $v1 = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CC-500-COKLAT', 'price' => 90000, 'weight_grams' => 500,
            'attributes' => ['Ukuran' => '500gr', 'Rasa' => 'Coklat'],
        ]);
        $v1->assertCreated();
        $this->assertSame('500gr / Coklat', $v1->json('data.label'));

        $v2 = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CC-1000-COKLAT', 'price' => 160000, 'weight_grams' => 1000,
            'attributes' => ['Ukuran' => '1kg', 'Rasa' => 'Coklat'],
        ]);
        $v2->assertCreated();

        // Exactly one "Rasa" attribute and one "Coklat" option exist for this product — reused, not duplicated.
        $this->assertSame(1, ProductVariationAttribute::query()
            ->where('product_id', $product->id)->where('name', 'Rasa')->count());
        $this->assertSame(1, ProductVariationAttributeOption::query()
            ->where('value', 'Coklat')->count());
    }

    public function test_variation_cannot_be_created_for_a_product_without_variations_enabled(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create([
            'sku' => 'ROTI-'.uniqid(),
            'name' => 'Roti Tawar', 'slug' => 'roti-tawar-'.uniqid(), 'has_variations' => false,
            'base_price' => 20000, 'weight_grams' => 400, 'status' => 'active',
        ]);

        $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'RT-1', 'price' => 20000, 'weight_grams' => 400, 'attributes' => ['Ukuran' => 'Besar'],
        ])->assertStatus(422);
    }

    public function test_admin_can_upload_a_product_image(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create([
            'sku' => 'DONAT-'.uniqid(),
            'name' => 'Donat', 'slug' => 'donat-'.uniqid(), 'has_variations' => false,
            'base_price' => 15000, 'weight_grams' => 200, 'status' => 'active',
        ]);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('donat.jpg'),
            'is_primary' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'is_primary' => true]);
    }

    /** Regression: ProductResource used to hand-roll `path` instead of a real URL, breaking every image preview in the storefront/admin. */
    public function test_product_detail_response_exposes_a_real_url_for_each_image_not_a_bare_path(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create([
            'sku' => 'NASTAR-KALENG-'.uniqid(),
            'name' => 'Nastar Kaleng', 'slug' => 'nastar-kaleng-'.uniqid(), 'has_variations' => false,
            'base_price' => 60000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('nastar.jpg'), 'is_primary' => true,
        ])->assertCreated();

        $response = $this->actingAs($superAdmin)->getJson("/api/v1/products/{$product->slug}");
        $response->assertOk();
        $this->assertArrayNotHasKey('path', $response->json('data.images.0'));
        $this->assertStringContainsString('/storage/', $response->json('data.images.0.url'));
    }

    /* ---------------------------------------------------------------
     * "Produk lainnya" — random related products on the detail page
     * ------------------------------------------------------------- */

    /** @return list<Product> */
    private function makeProducts(int $count, string $status = 'active'): array
    {
        return collect(range(1, $count))->map(fn ($i) => Product::create([
            'sku' => 'RND-'.strtoupper($status).'-'.uniqid(),
            'name' => 'Produk '.$status.' '.uniqid(),
            'slug' => 'produk-'.$status.'-'.uniqid(),
            'has_variations' => false, 'base_price' => 10000, 'status' => $status,
        ]))->all();
    }

    public function test_random_products_never_include_the_excluded_current_product(): void
    {
        [$current] = $this->makeProducts(1);
        $this->makeProducts(5);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson('/api/v1/products?random=1&exclude='.$current->id.'&per_page=8');
            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertNotContains($current->id, $ids);
        }
    }

    public function test_random_products_only_contain_active_products(): void
    {
        [$current] = $this->makeProducts(1);
        $this->makeProducts(3, 'active');
        $this->makeProducts(3, 'draft');
        $this->makeProducts(3, 'inactive');

        $response = $this->getJson('/api/v1/products?random=1&exclude='.$current->id.'&per_page=20');
        $response->assertOk();
        $statuses = collect($response->json('data'))->pluck('status')->unique();
        $this->assertEquals(collect(['active']), $statuses->values());
    }

    public function test_random_products_are_limited_to_the_requested_amount(): void
    {
        [$current] = $this->makeProducts(1);
        $this->makeProducts(12);

        $response = $this->getJson('/api/v1/products?random=1&exclude='.$current->id.'&per_page=8');
        $response->assertOk();
        $this->assertCount(8, $response->json('data'));
    }

    public function test_product_can_be_created_and_updated_with_a_short_description(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $created = $this->actingAs($superAdmin)->postJson('/api/v1/products', ['sku' => 'TEST-'.(string) Str::uuid(),
            'name' => 'Kue Lapis', 'has_variations' => false, 'base_price' => 35000, 'weight_grams' => 400,
            'short_description' => 'Kue lapis legit khas Nusantara.',
        ]);
        $created->assertCreated();
        $this->assertSame('Kue lapis legit khas Nusantara.', $created->json('data.short_description'));

        $productId = $created->json('data.id');
        $updated = $this->actingAs($superAdmin)->patchJson("/api/v1/products/{$productId}", [
            'short_description' => 'Diperbarui.',
        ]);
        $updated->assertOk();
        $this->assertSame('Diperbarui.', $updated->json('data.short_description'));
    }

    public function test_setting_an_image_as_primary_unsets_every_other_image_on_the_same_product(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create([
            'sku' => 'KLAPPERTAART-'.uniqid(),
            'name' => 'Klappertaart', 'slug' => 'klappertaart-'.uniqid(), 'has_variations' => false,
            'base_price' => 45000, 'weight_grams' => 400, 'status' => 'active',
        ]);

        $imageA = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('a.jpg'), 'is_primary' => true,
        ])->json('data.id');
        $imageB = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('b.jpg'),
        ])->json('data.id');

        $this->actingAs($superAdmin)->patchJson("/api/v1/products/{$product->id}/images/{$imageB}", ['is_primary' => true])
            ->assertOk()->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('product_images', ['id' => $imageA, 'is_primary' => false]);
        $this->assertDatabaseHas('product_images', ['id' => $imageB, 'is_primary' => true]);
    }

    /* ---------------------------------------------------------------
     * Stock isolation & business rules
     * ------------------------------------------------------------- */

    public function test_product_without_variation_stores_stock_on_product_not_variation(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create([
            'sku' => 'KUE-LAPIS-'.uniqid(),
            'name' => 'Kue Lapis', 'slug' => 'kue-lapis-'.uniqid(), 'has_variations' => false,
            'base_price' => 60000, 'weight_grams' => 700, 'status' => 'active',
        ]);

        $response = $this->actingAs($branch['agen'])->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => 25, 'reason' => 'Stok awal',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 25]);
        $this->assertDatabaseCount('product_variation_stocks', 0);
    }

    public function test_product_with_variation_rejects_stock_adjustment_on_the_parent_product(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create([
            'name' => 'Cake Susun', 'slug' => 'cake-susun-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);

        $this->actingAs($branch['agen'])->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => 10, 'reason' => 'Coba-coba',
        ])->assertStatus(422);
    }

    public function test_stock_for_the_same_product_variation_is_isolated_per_agent_and_never_summed(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();

        $product = Product::create([
            'name' => 'Cake Chocolate', 'slug' => 'cake-chocolate-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);
        $superAdmin = User::factory()->superAdmin()->create();
        $variationId = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CC-500', 'price' => 90000, 'weight_grams' => 500, 'attributes' => ['Ukuran' => '500gr'],
        ])->json('data.id');

        $this->actingAs($branchA['agen'])->postJson('/api/v1/stock/adjust', [
            'product_variation_id' => $variationId, 'delta' => 10, 'reason' => 'Stok awal A',
        ])->assertOk();

        $this->actingAs($branchB['agen'])->postJson('/api/v1/stock/adjust', [
            'product_variation_id' => $variationId, 'delta' => 20, 'reason' => 'Stok awal B',
        ])->assertOk();

        $this->assertDatabaseHas('product_variation_stocks', [
            'agent_id' => $branchA['agen']->id, 'product_variation_id' => $variationId, 'quantity_on_hand' => 10,
        ]);
        $this->assertDatabaseHas('product_variation_stocks', [
            'agent_id' => $branchB['agen']->id, 'product_variation_id' => $variationId, 'quantity_on_hand' => 20,
        ]);
    }

    public function test_stock_can_never_be_adjusted_below_zero(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Pie Buah', 'slug' => 'pie-buah-'.uniqid(), 'has_variations' => false,
            'base_price' => 45000, 'weight_grams' => 600, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);

        $this->actingAs($branch['agen'])->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => -10, 'reason' => 'Koreksi berlebihan',
        ])->assertStatus(422);

        $this->assertDatabaseHas('product_stocks', ['product_id' => $product->id, 'quantity_on_hand' => 5]);
    }

    public function test_agen_cannot_adjust_another_agents_stock(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Cookies', 'slug' => 'cookies-'.uniqid(), 'has_variations' => false,
            'base_price' => 30000, 'weight_grams' => 250, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branchB['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        // Agent A has no way to even name Agent B — the request has no agent_id field for
        // a non-super_admin actor, so this always resolves to Agent A's OWN branch: a
        // fresh, zero-based row gets created for Agent A, completely independent of B's.
        $this->actingAs($branchA['agen'])->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => 5, 'reason' => 'stok milik A sendiri',
        ])->assertOk();

        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branchA['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 5]);

        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branchB['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10]);
    }

    public function test_sales_and_konsumen_cannot_adjust_stock_at_all(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Muffin', 'slug' => 'muffin-'.uniqid(), 'has_variations' => false,
            'base_price' => 12000, 'weight_grams' => 150, 'status' => 'active',
        ]);

        foreach (['sales', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])->postJson('/api/v1/stock/adjust', [
                'product_id' => $product->id, 'delta' => 5, 'reason' => 'x',
            ])->assertStatus(403);
        }
    }

    /** "Stok hanya untuk agen dan admin di bawah jaringan agen tersebut" — super_admin may browse stock but never mutate it. */
    public function test_super_admin_cannot_adjust_stock_but_can_still_browse_it(): void
    {
        $branch = $this->makeAgentBranch();
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Brownies', 'slug' => 'brownies-'.uniqid(), 'has_variations' => false,
            'base_price' => 25000, 'weight_grams' => 300, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $this->actingAs($superAdmin)->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => 5, 'reason' => 'super admin mencoba ubah stok', 'agent_id' => $branch['agen']->id,
        ])->assertStatus(403);

        // Read-only browsing/oversight is untouched.
        $this->actingAs($superAdmin)->getJson('/api/v1/stock/products?agent_id='.$branch['agen']->id)->assertOk();

        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10]);
    }

    /** Admin (per-agent staff) keeps stock-adjust rights, same as agen. */
    public function test_admin_can_still_adjust_stock_within_their_own_agent(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Donat', 'slug' => 'donat-'.uniqid(), 'has_variations' => false,
            'base_price' => 15000, 'weight_grams' => 100, 'status' => 'active',
        ]);

        $this->actingAs($branch['admin'])->postJson('/api/v1/stock/adjust', [
            'product_id' => $product->id, 'delta' => 8, 'reason' => 'stok awal oleh admin',
        ])->assertOk();

        $this->assertDatabaseHas('product_stocks', ['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 8]);
    }

    /* ---------------------------------------------------------------
     * Purchasing is confined to the konsumen's own referral-network agent
     * ------------------------------------------------------------- */

    public function test_konsumen_purchase_only_ever_draws_from_their_own_networks_agent_stock(): void
    {
        $ownBranch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Brownies', 'slug' => 'brownies-'.uniqid(), 'has_variations' => false,
            'base_price' => 35000, 'weight_grams' => 400, 'status' => 'active',
        ]);

        // Own agent has NO stock; the other agent has plenty — must not "fall back" to it.
        ProductStock::create(['agent_id' => $otherBranch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 100, 'quantity_reserved' => 0]);

        $response = $this->actingAs($ownBranch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        // The other agent's stock is completely untouched.
        $this->assertDatabaseHas('product_stocks', ['agent_id' => $otherBranch['agen']->id, 'quantity_on_hand' => 100, 'quantity_reserved' => 0]);
    }

    public function test_agent_product_availability_reflects_only_the_viewers_own_network_agent(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Klappertaart', 'slug' => 'klappertaart-'.uniqid(), 'has_variations' => false,
            'base_price' => 55000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branchA['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 7, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $branchB['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 42, 'quantity_reserved' => 0]);

        $asA = $this->actingAs($branchA['konsumen'])->getJson("/api/v1/products/{$product->slug}");
        $asA->assertOk();
        $this->assertSame(7, $asA->json('data.agent_available_quantity'));

        $asB = $this->actingAs($branchB['konsumen'])->getJson("/api/v1/products/{$product->slug}");
        $asB->assertOk();
        $this->assertSame(42, $asB->json('data.agent_available_quantity'));

        // A guest sees the catalog without any per-agent figure at all. actingAs() leaves
        // the previous user resolved for the guard until explicitly logged out here.
        Auth::logout();
        $asGuest = $this->getJson("/api/v1/products/{$product->slug}");
        $asGuest->assertOk();
        $this->assertArrayNotHasKey('agent_available_quantity', $asGuest->json('data'));
    }

    /* ---------------------------------------------------------------
     * Overselling prevention under row locking
     * ------------------------------------------------------------- */

    public function test_two_orders_racing_for_the_last_unit_never_both_succeed(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Limited Cake', 'slug' => 'limited-cake-'.uniqid(), 'has_variations' => false,
            'base_price' => 200000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 0]);

        $konsumen2 = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $branch['sales']->id,
        ]);

        $payload = [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'X', 'recipient_phone' => '0812', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ];

        $first = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $payload);
        $second = $this->actingAs($konsumen2)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $payload);

        $statuses = collect([$first->status(), $second->status()])->sort()->values();
        $this->assertSame([201, 422], $statuses->all());

        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $product->id)->where('agent_id', $branch['agen']->id)->first();
        $this->assertSame(1, $stock->quantity_reserved);
        $this->assertSame(0, $stock->availableQuantity());
    }
}
