<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** REM-02/03/04/05/06/08 catalog remediation regressions. */
class CatalogRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function sa(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function agen(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);

        return $a;
    }

    private function product(string $sku, string $status = 'active', bool $variations = false): Product
    {
        return Product::create([
            'name' => 'P '.$sku, 'slug' => 'p-'.$sku, 'sku' => $variations ? null : $sku, 'has_variations' => $variations,
            'base_price' => $variations ? null : 1000, 'weight_grams' => $variations ? null : 100, 'status' => $status,
        ]);
    }

    private function variation(Product $p, string $sku, array $attrs): ProductVariation
    {
        return app(\App\Services\Product\ProductVariationService::class)
            ->create($p, $attrs, ['sku' => $sku, 'price' => 5000, 'weight_grams' => 200]);
    }

    private function img(): UploadedFile
    {
        return UploadedFile::fake()->image('a.jpg', 20, 20);
    }

    /* REM-05 / REM-03 */
    public function test_management_listing_filters_by_status_defaults_to_all_and_storefront_stays_active_only(): void
    {
        $this->product('A1', 'active');
        $this->product('D1', 'draft');
        $this->product('I1', 'inactive');
        $sa = $this->sa();
        $this->getJson('/api/v1/manage/products')->assertStatus(401);

        $this->actingAs($sa)->getJson('/api/v1/manage/products')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.status_filter', 'all');
        foreach (['active' => 1, 'draft' => 1, 'inactive' => 1, 'all' => 3] as $status => $count) {
            $this->actingAs($sa)->getJson('/api/v1/manage/products?status='.$status)->assertOk()->assertJsonPath('meta.total', $count);
        }
        $this->actingAs($sa)->getJson('/api/v1/manage/products?status=deleted')->assertStatus(422);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
        $konsumen = User::factory()->konsumen()->create();
        $this->actingAs($konsumen)->getJson('/api/v1/manage/products')->assertStatus(403);
    }

    public function test_management_detail_returns_every_image_and_inactive_variants(): void
    {
        $p = $this->product('V', 'draft', true);
        $v1 = $this->variation($p, 'V-1', ['Ukuran' => 'S']);
        $v2 = $this->variation($p, 'V-2', ['Ukuran' => 'M']);
        $v2->update(['is_active' => false]);
        ProductImage::create(['product_id' => $p->id, 'path' => 'products/a.jpg', 'is_primary' => true]);
        ProductImage::create(['product_id' => $p->id, 'product_variation_id' => $v1->id, 'path' => 'products/b.jpg', 'is_primary' => true]);
        ProductImage::create(['product_id' => $p->id, 'product_variation_id' => $v2->id, 'path' => 'products/c.jpg', 'is_primary' => false]);

        $r = $this->actingAs($this->sa())->getJson('/api/v1/manage/products/'.$p->id)->assertOk();
        $r->assertJsonCount(3, 'data.images')->assertJsonCount(2, 'data.variations');
        $this->assertSame(['Ukuran' => 'S'], $r->json('data.variations.0.attributes'));
        // storefront never leaks a draft product
        $this->getJson('/api/v1/products/'.$p->slug)->assertStatus(404);
    }

    /* REM-02 */
    public function test_variant_gallery_is_isolated_and_primary_is_scoped_per_gallery(): void
    {
        $sa = $this->sa();
        $p = $this->product('G', 'active', true);
        $other = $this->product('H', 'active', true);
        $v1 = $this->variation($p, 'G-1', ['W' => 'a']);
        $v2 = $this->variation($p, 'G-2', ['W' => 'b']);
        $foreign = $this->variation($other, 'H-1', ['W' => 'a']);

        // cross-parent attachment rejected
        $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'product_variation_id' => $foreign->id])
            ->assertStatus(422);

        $parent = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'is_primary' => true])->assertCreated()->json('data.id');
        $a = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'product_variation_id' => $v1->id, 'is_primary' => true])->assertCreated()->json('data.id');
        $b = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'product_variation_id' => $v2->id, 'is_primary' => true])->assertCreated()->json('data.id');

        // each gallery keeps its own primary
        $this->assertTrue(ProductImage::find($parent)->is_primary);
        $this->assertTrue(ProductImage::find($a)->is_primary);
        $this->assertTrue(ProductImage::find($b)->is_primary);

        // promoting a second parent image demotes only the parent gallery
        $parent2 = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'is_primary' => true])->assertCreated()->json('data.id');
        $this->assertFalse(ProductImage::find($parent)->is_primary);
        $this->assertTrue(ProductImage::find($parent2)->is_primary);
        $this->assertTrue(ProductImage::find($a)->is_primary);

        // patch primary is scoped as well; foreign product image path is 404
        $a2 = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img(), 'product_variation_id' => $v1->id])->assertCreated()->json('data.id');
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/images/{$a2}", ['is_primary' => true])->assertOk();
        $this->assertFalse(ProductImage::find($a)->is_primary);
        $this->assertTrue(ProductImage::find($b)->is_primary);
        $this->assertTrue(ProductImage::find($parent2)->is_primary);
        $this->actingAs($sa)->patchJson("/api/v1/products/{$other->id}/images/{$a2}", ['is_primary' => true])->assertStatus(404);

        // storefront card shows the parent primary only
        $card = $this->getJson('/api/v1/products')->assertOk()->json('data');
        $mine = collect($card)->firstWhere('id', $p->id);
        $this->assertCount(1, $mine['images']);
        $this->assertNull($mine['images'][0]['product_variation_id']);
    }

    /* REM-06 */
    public function test_category_parent_validation_rejects_self_descendant_and_missing_parents(): void
    {
        $sa = $this->sa();
        $root = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Root'])->assertCreated()->json('data.id');
        $child = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Child', 'parent_id' => $root])->assertCreated()->json('data.id');
        $grand = $this->actingAs($sa)->postJson('/api/v1/categories', ['name' => 'Grand', 'parent_id' => $child])->assertCreated()->json('data.id');

        $this->actingAs($sa)->patchJson("/api/v1/categories/{$root}", ['parent_id' => $root])->assertStatus(422);
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$root}", ['parent_id' => $grand])->assertStatus(422);
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$root}", ['parent_id' => 999999])->assertStatus(422);
        $this->assertNull(ProductCategory::find($root)->parent_id);
        // legal re-parent still works
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$grand}", ['parent_id' => $root])->assertOk();
        // detaching to root works
        $this->actingAs($sa)->patchJson("/api/v1/categories/{$grand}", ['parent_id' => null])->assertOk();
        $this->assertNull(ProductCategory::find($grand)->parent_id);
    }

    public function test_parent_category_filter_includes_descendant_products_and_existing_products_are_kept(): void
    {
        $sa = $this->sa();
        $root = ProductCategory::create(['name' => 'R', 'slug' => 'r']);
        $kid = ProductCategory::create(['name' => 'K', 'slug' => 'k', 'parent_id' => $root->id]);
        $a = $this->product('CA');
        $a->update(['category_id' => $kid->id]);
        $b = $this->product('CB');

        $this->getJson('/api/v1/products?category=r')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/products?category=k')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($sa)->getJson("/api/v1/manage/products?category_id={$root->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($kid->id, $a->fresh()->category_id);
        $this->assertNull($b->fresh()->category_id);
    }

    /* REM-08 */
    public function test_editing_one_variant_attributes_does_not_touch_siblings_or_order_snapshots(): void
    {
        $sa = $this->sa();
        $p = $this->product('AT', 'active', true);
        $v1 = $this->variation($p, 'AT-1', ['Ukuran' => 'S']);
        $v2 = $this->variation($p, 'AT-2', ['Ukuran' => 'S']);
        // two siblings share the same option row 'S' — must stay that way for v2

        $agent = $this->agen();
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $order = \App\Models\Order::create(['order_no' => 'ATTR-SNAPSHOT', 'agent_id' => $agent->id, 'konsumen_id' => $buyer->id,
            'subtotal_amount' => 5000, 'total_amount' => 5000, 'recipient_name_snapshot' => 'Buyer', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Jl. Buyer']);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'product_variation_id' => $v1->id,
            'product_name_snapshot' => $p->name, 'variation_label_snapshot' => 'S', 'sku_snapshot' => $v1->sku, 'unit_price_snapshot' => 5000,
            'subtotal_snapshot' => 5000, 'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'terkirim']);
        $itemBefore = $item->fresh()->getRawOriginal();
        $gallery = \App\Models\ProductImage::create(['product_id' => $p->id, 'product_variation_id' => $v1->id, 'path' => 'variant.jpg', 'is_primary' => true, 'sort_order' => 0]);

        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$v1->id}", ['attributes' => ['Ukuran' => 'XL']])
            ->assertOk()->assertJsonPath('data.attributes.Ukuran', 'XL');
        $this->assertSame('XL', $v1->fresh()->label());
        $this->assertSame('S', $v2->fresh()->label());
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
        $this->assertSame('variant.jpg', $gallery->fresh()->path);

        // duplicate composition among siblings is rejected and leaves data unchanged
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$v1->id}", ['attributes' => ['Ukuran' => 'S']])->assertStatus(422);
        $this->assertSame('XL', $v1->fresh()->label());

        // validation
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$v1->id}", ['attributes' => ['Ukuran' => '']])->assertStatus(422);
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$v1->id}", ['attributes' => []])->assertStatus(422);

        // variant from another product is not editable through this product
        $other = $this->product('AT2', 'active', true);
        $ov = $this->variation($other, 'AT2-1', ['Ukuran' => 'M']);
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$ov->id}", ['attributes' => ['Ukuran' => 'Z']])->assertStatus(404);
        $this->assertSame('M', $ov->fresh()->label());

        // commercial-only update still works without attributes
        $this->actingAs($sa)->patchJson("/api/v1/products/{$p->id}/variations/{$v1->id}", ['price' => 7000])->assertOk();
        $this->assertSame('XL', $v1->fresh()->label());
    }

    /* REM-04 */
    public function test_hard_delete_is_guarded_denied_for_admin_and_blocked_by_references(): void
    {
        $sa = $this->sa();
        $admin = User::factory()->admin()->create();
        $agen = $this->agen();
        $p = $this->product('HD1');

        $this->actingAs($admin)->deleteJson("/api/v1/products/{$p->id}/permanent", ['confirm' => true])->assertStatus(403);
        $this->actingAs($sa)->deleteJson("/api/v1/products/{$p->id}/permanent")->assertStatus(422); // no confirmation
        $this->assertNotNull(Product::find($p->id));

        // an inventory row blocks it
        ProductStock::create(['product_id' => $p->id, 'agent_id' => $agen->id, 'quantity' => 3]);
        $r = $this->actingAs($sa)->deleteJson("/api/v1/products/{$p->id}/permanent", ['confirm' => true])->assertStatus(409);
        $this->assertArrayHasKey('product_stocks', $r->json('errors.references') ?? $r->json('references') ?? []);
        $this->assertNotNull(Product::find($p->id));
    }

    public function test_hard_delete_removes_unreferenced_product_with_variants_images_and_sku_and_cleans_files_after_commit(): void
    {
        $sa = $this->sa();
        $p = $this->product('HD2', 'draft', true);
        $v = $this->variation($p, 'HD2-1', ['K' => 'x']);
        $path = $this->actingAs($sa)->postJson("/api/v1/products/{$p->id}/images", ['image' => $this->img()])->assertCreated()->json('data.url');
        $file = ProductImage::where('product_id', $p->id)->value('path');
        Storage::disk('public')->assertExists($file);

        $this->actingAs($sa)->deleteJson("/api/v1/products/{$p->id}/permanent", ['confirm' => true])->assertOk();

        $this->assertNull(Product::withTrashed()->find($p->id));
        $this->assertSame(0, DB::table('product_variations')->where('product_id', $p->id)->count());
        $this->assertSame(0, DB::table('catalog_skus')->whereIn('sku', ['HD2-1'])->count());
        Storage::disk('public')->assertMissing($file);
        $this->assertDatabaseHas('activity_logs', ['event' => 'product.hard_deleted', 'subject_id' => $p->id]);
        // SKU is reusable afterwards
        $this->product('HD2-1');
    }

    public function test_soft_delete_remains_distinct_and_agen_may_hard_delete_unreferenced(): void
    {
        $sa = $this->sa();
        $agen = $this->agen();
        $p = $this->product('HD3');
        $this->actingAs($sa)->deleteJson("/api/v1/products/{$p->id}")->assertOk();
        $this->assertNotNull(Product::withTrashed()->find($p->id));
        $this->actingAs($agen)->deleteJson("/api/v1/products/{$p->id}/permanent", ['confirm' => true])->assertOk();
        $this->assertNull(Product::withTrashed()->find($p->id));
    }
}
