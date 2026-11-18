<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use App\Models\User;
use App\Services\Product\ProductVariationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REM-02 / REM-03 / REM-08 (unified remediation): per-variation image galleries — upload,
 * primary, REORDER, safe removal — isolation between variations, and image stability across
 * product saves. Real database; no mocks.
 */
class VariationGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function agen(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);

        return $a;
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Kue', 'slug' => 'kue', 'sku' => null, 'has_variations' => true, 'base_price' => null, 'weight_grams' => null, 'status' => 'active']);
    }

    private function variation(Product $p, string $sku, array $attrs): ProductVariation
    {
        return app(ProductVariationService::class)->create($p, $attrs, ['sku' => $sku, 'price' => 5000, 'weight_grams' => 200]);
    }

    private function upload(User $u, Product $p, ?int $variationId, bool $primary = false): int
    {
        $payload = ['image' => UploadedFile::fake()->image('i.jpg', 20, 20), 'is_primary' => $primary];
        if ($variationId !== null) {
            $payload['product_variation_id'] = $variationId;
        }

        return $this->actingAs($u)->postJson("/api/v1/products/{$p->id}/images", $payload)->assertCreated()->json('data.id');
    }

    /** @return array<int, array<int, int>> variation_id(0=parent) => ordered image ids */
    private function galleries(Product $p): array
    {
        return ProductImage::where('product_id', $p->id)->orderBy('sort_order')->orderBy('id')->get()
            ->groupBy(fn ($i) => (int) $i->product_variation_id)->map(fn ($g) => $g->pluck('id')->all())->all();
    }

    public function test_create_two_variations_with_distinct_images_then_edit_only_one_leaves_the_other_untouched(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        $b = $this->variation($p, 'K-B', ['Rasa' => 'Keju']);
        $a1 = $this->upload($u, $p, $a->id, true);
        $b1 = $this->upload($u, $p, $b->id, true);
        $pathB = ProductImage::find($b1)->path;
        $before = $this->galleries($p);

        // edit only variation A: add an image, make it primary, remove the old one
        $a2 = $this->upload($u, $p, $a->id, true);
        $this->actingAs($u)->deleteJson("/api/v1/products/{$p->id}/images/{$a1}")->assertOk();

        $after = $this->galleries($p);
        $this->assertSame([$a2], $after[$a->id]);
        $this->assertSame($before[$b->id], $after[$b->id], 'variation B gallery must be byte-for-byte untouched');
        $this->assertTrue(ProductImage::find($b1)->is_primary);
        $this->assertSame($pathB, ProductImage::find($b1)->path);
        Storage::disk('public')->assertExists($pathB);
        $this->assertTrue(ProductImage::find($a2)->is_primary);
    }

    public function test_reorder_applies_only_to_one_gallery_and_requires_the_exact_image_set(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        $b = $this->variation($p, 'K-B', ['Rasa' => 'Keju']);
        [$a1, $a2, $a3] = [$this->upload($u, $p, $a->id, true), $this->upload($u, $p, $a->id), $this->upload($u, $p, $a->id)];
        [$b1, $b2] = [$this->upload($u, $p, $b->id, true), $this->upload($u, $p, $b->id)];
        $bOrderBefore = $this->galleries($p)[$b->id];

        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $a->id, 'ids' => [$a3, $a1, $a2]])
            ->assertOk()->assertJsonPath('data.0.id', $a3);
        $g = $this->galleries($p);
        $this->assertSame([$a3, $a1, $a2], $g[$a->id]);
        $this->assertSame($bOrderBefore, $g[$b->id]);
        $this->assertTrue(ProductImage::find($a1)->is_primary, 'reordering never changes the primary');

        // partial list, foreign image, duplicate and unknown variation are all rejected without change
        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $a->id, 'ids' => [$a3, $a1]])->assertStatus(422);
        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $a->id, 'ids' => [$a3, $a1, $b1]])->assertStatus(422);
        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $a->id, 'ids' => [$a3, $a3, $a1]])->assertStatus(422);
        $other = Product::create(['name' => 'X', 'slug' => 'x', 'has_variations' => true, 'status' => 'active']);
        $foreign = $this->variation($other, 'X-1', ['W' => 'a']);
        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $foreign->id, 'ids' => [$a1]])->assertStatus(422);
        $this->assertSame([$a3, $a1, $a2], $this->galleries($p)[$a->id]);
        $this->assertSame([$b1, $b2], $this->galleries($p)[$b->id]);
    }

    public function test_removing_the_primary_promotes_the_next_image_of_the_same_gallery_only(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        $b = $this->variation($p, 'K-B', ['Rasa' => 'Keju']);
        $a1 = $this->upload($u, $p, $a->id, true);
        $a2 = $this->upload($u, $p, $a->id);
        $b1 = $this->upload($u, $p, $b->id, true);

        $this->actingAs($u)->deleteJson("/api/v1/products/{$p->id}/images/{$a1}")->assertOk();
        $this->assertTrue(ProductImage::find($a2)->is_primary);
        $this->assertTrue(ProductImage::find($b1)->is_primary);

        // deleting the last image of a gallery leaves that gallery empty (no cross-gallery promotion)
        $this->actingAs($u)->deleteJson("/api/v1/products/{$p->id}/images/{$a2}")->assertOk();
        $this->assertArrayNotHasKey($a->id, $this->galleries($p));
        $this->assertTrue(ProductImage::find($b1)->is_primary);
    }

    public function test_only_catalog_roles_can_manage_galleries(): void
    {
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        $img = $this->upload($this->agen(), $p, $a->id, true);
        $konsumen = User::factory()->konsumen()->create();
        foreach ([
            ['putJson', "/api/v1/products/{$p->id}/images/order", ['ids' => [$img], 'product_variation_id' => $a->id]],
            ['deleteJson', "/api/v1/products/{$p->id}/images/{$img}", []],
            ['postJson', "/api/v1/products/{$p->id}/images", ['image' => UploadedFile::fake()->image('i.jpg'), 'product_variation_id' => $a->id]],
        ] as [$m, $url, $body]) {
            $this->actingAs($konsumen)->{$m}($url, $body)->assertStatus(403);
        }
        $this->assertTrue(ProductImage::whereKey($img)->exists());
    }

    public function test_upload_validation_rejects_non_images_oversize_and_svg(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        foreach ([UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('big.jpg')->size(6000)] as $file) {
            $this->actingAs($u)->postJson("/api/v1/products/{$p->id}/images", ['image' => $file, 'product_variation_id' => $a->id])->assertStatus(422);
        }
        $this->assertSame(0, ProductImage::count());
    }

    public function test_saving_product_fields_never_changes_images(): void
    {
        $u = $this->agen();
        $cat = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue']);
        $p = Product::create(['name' => 'Roti', 'slug' => 'roti', 'sku' => 'R-1', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active', 'category_id' => $cat->id, 'description' => 'asli']);
        $i1 = $this->upload($u, $p, null, true);
        $i2 = $this->upload($u, $p, null);
        $snapshot = ProductImage::where('product_id', $p->id)->orderBy('id')->get(['id', 'path', 'is_primary', 'sort_order', 'product_variation_id'])->toArray();

        foreach ([['name' => 'Roti Baru'], ['description' => 'baru'], ['base_price' => 2000], ['status' => 'inactive'], [], ['images' => []], ['images' => null]] as $payload) {
            $this->actingAs($u)->patchJson("/api/v1/products/{$p->id}", $payload)->assertOk();
            $this->assertSame($snapshot, ProductImage::where('product_id', $p->id)->orderBy('id')->get(['id', 'path', 'is_primary', 'sort_order', 'product_variation_id'])->toArray(), 'payload '.json_encode($payload));
        }
        $this->assertSame($cat->id, $p->refresh()->category_id, 'omitted fields stay untouched');
        Storage::disk('public')->assertExists(ProductImage::find($i1)->path);
        Storage::disk('public')->assertExists(ProductImage::find($i2)->path);
    }

    public function test_storefront_detail_returns_images_in_the_saved_order(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        [$a1, $a2] = [$this->upload($u, $p, $a->id, true), $this->upload($u, $p, $a->id)];
        $this->actingAs($u)->putJson("/api/v1/products/{$p->id}/images/order", ['product_variation_id' => $a->id, 'ids' => [$a2, $a1]])->assertOk();

        $ids = collect($this->getJson('/api/v1/products/'.$p->slug)->assertOk()->json('data.images'))->pluck('id')->all();
        $this->assertSame([$a2, $a1], $ids);
    }

    public function test_variation_label_edit_does_not_move_images_to_another_variation(): void
    {
        $u = $this->agen();
        $p = $this->product();
        $a = $this->variation($p, 'K-A', ['Rasa' => 'Coklat']);
        $b = $this->variation($p, 'K-B', ['Rasa' => 'Keju']);
        $ia = $this->upload($u, $p, $a->id, true);
        $ib = $this->upload($u, $p, $b->id, true);
        $this->actingAs($u)->patchJson("/api/v1/products/{$p->id}/variations/{$a->id}", ['attributes' => ['Rasa' => 'Keju Susu']])->assertOk();
        $this->assertSame($a->id, ProductImage::find($ia)->product_variation_id);
        $this->assertSame($b->id, ProductImage::find($ib)->product_variation_id);
    }
}
