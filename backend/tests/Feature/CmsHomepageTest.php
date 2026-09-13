<?php

namespace Tests\Feature;

use App\Models\CmsHomepageBlock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CmsHomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    /* ---------------------------------------------------------------
     * Authorization
     * ------------------------------------------------------------- */

    public function test_only_super_admin_can_create_a_homepage_block(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($agen)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'text',
            'content' => ['body' => 'Halo'],
        ])->assertStatus(403);
    }

    public function test_guest_cannot_manage_homepage_blocks(): void
    {
        $this->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'text', 'content' => ['body' => 'Halo'],
        ])->assertStatus(401);
    }

    /* ---------------------------------------------------------------
     * Create — every block type + image upload with real validation
     * ------------------------------------------------------------- */

    public function test_super_admin_can_create_a_text_block_without_an_image(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'text',
            'content' => ['heading' => 'Tentang Kami', 'body' => 'Prime Classy adalah toko kue rumahan.'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('cms_homepage_blocks', ['type' => 'text']);
        $this->assertNull($response->json('data.image_url'));
    }

    public function test_super_admin_can_create_a_hero_block_with_a_real_uploaded_image(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'hero',
            'content' => ['heading' => 'Kue Segar Setiap Hari'],
            'image' => UploadedFile::fake()->image('hero.jpg', 1200, 600),
        ]);

        $response->assertCreated();
        $block = CmsHomepageBlock::query()->findOrFail($response->json('data.id'));
        $this->assertNotNull($block->content['image_path']);
        Storage::disk('public')->assertExists($block->content['image_path']);
        $this->assertNotNull($response->json('data.image_url'));
    }

    public function test_image_upload_rejects_non_image_mime_types(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'banner',
            'content' => [],
            'image' => UploadedFile::fake()->create('malware.php', 10, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_image_upload_rejects_files_over_the_size_limit(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'banner',
            'content' => [],
            'image' => UploadedFile::fake()->image('huge.jpg')->size(4096), // 4MB > 2MB limit
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_a_block_type_that_does_not_accept_an_image_rejects_one(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'text',
            'content' => ['body' => 'Halo'],
            'image' => UploadedFile::fake()->image('irrelevant.jpg'),
        ])->assertStatus(422);
    }

    public function test_unknown_block_type_is_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'not-a-real-type',
            'content' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_content_validation_is_specific_to_the_block_type(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // CTA requires heading/button_label/button_url — none supplied.
        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'cta',
            'content' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['content.heading', 'content.button_label', 'content.button_url']);
    }

    public function test_custom_block_html_is_sanitized_before_storage(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'custom',
            'content' => ['html' => '<p>Halo</p><script>alert(1)</script>'],
        ]);

        $response->assertCreated();
        $block = CmsHomepageBlock::query()->findOrFail($response->json('data.id'));
        $this->assertStringNotContainsString('<script>', $block->content['html']);
        $this->assertStringContainsString('<p>Halo</p>', $block->content['html']);
    }

    /* ---------------------------------------------------------------
     * Update, reorder, toggle, delete
     * ------------------------------------------------------------- */

    public function test_updating_a_block_replaces_its_image_and_deletes_the_old_file(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $created = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'banner', 'content' => [],
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $block = CmsHomepageBlock::query()->findOrFail($created->json('data.id'));
        $oldPath = $block->content['image_path'];
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($superAdmin)->post("/api/v1/cms/homepage-blocks/{$block->id}", [
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        $newPath = $block->fresh()->content['image_path'];
        Storage::disk('public')->assertExists($newPath);
        $this->assertNotSame($oldPath, $newPath);
    }

    public function test_reorder_updates_sort_order_to_match_the_given_sequence(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $a = CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'A'], 'sort_order' => 0, 'is_active' => true]);
        $b = CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'B'], 'sort_order' => 1, 'is_active' => true]);
        $c = CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'C'], 'sort_order' => 2, 'is_active' => true]);

        $this->actingAs($superAdmin)->patchJson('/api/v1/cms/homepage-blocks/reorder', [
            'block_ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        $this->assertSame(0, $c->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_toggle_flips_is_active_and_public_homepage_reflects_it_immediately(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $block = CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'X'], 'sort_order' => 0, 'is_active' => true]);

        $this->getJson('/api/v1/homepage')->assertJsonCount(1, 'data');

        $this->actingAs($superAdmin)->patchJson("/api/v1/cms/homepage-blocks/{$block->id}/toggle")->assertOk();
        $this->assertFalse($block->fresh()->is_active);

        $this->getJson('/api/v1/homepage')->assertJsonCount(0, 'data');
    }

    public function test_deleting_a_block_removes_its_image_file_too(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $created = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'hero', 'content' => ['heading' => 'X'],
            'image' => UploadedFile::fake()->image('hero.jpg'),
        ]);
        $block = CmsHomepageBlock::query()->findOrFail($created->json('data.id'));
        $path = $block->content['image_path'];
        Storage::disk('public')->assertExists($path);

        $this->actingAs($superAdmin)->deleteJson("/api/v1/cms/homepage-blocks/{$block->id}")->assertOk();

        $this->assertDatabaseMissing('cms_homepage_blocks', ['id' => $block->id]);
        Storage::disk('public')->assertMissing($path);
    }

    /* ---------------------------------------------------------------
     * Public rendering feed
     * ------------------------------------------------------------- */

    public function test_public_homepage_only_returns_active_blocks_within_their_scheduling_window_in_order(): void
    {
        CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'Active'], 'sort_order' => 1, 'is_active' => true]);
        CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'Inactive'], 'sort_order' => 0, 'is_active' => false]);
        CmsHomepageBlock::create([
            'type' => 'text', 'content' => ['body' => 'Future'], 'sort_order' => 2, 'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);
        CmsHomepageBlock::create([
            'type' => 'text', 'content' => ['body' => 'Expired'], 'sort_order' => 3, 'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/v1/homepage');

        $response->assertOk();
        $bodies = collect($response->json('data'))->pluck('content.body')->all();
        $this->assertSame(['Active'], $bodies);
    }

    public function test_public_homepage_resolves_category_references(): void
    {
        $cat1 = ProductCategory::create(['name' => 'Cake', 'slug' => 'cake-'.uniqid(), 'is_active' => true]);
        $cat2 = ProductCategory::create(['name' => 'Cookies', 'slug' => 'cookies-'.uniqid(), 'is_active' => true]);
        CmsHomepageBlock::create([
            'type' => 'category', 'content' => ['category_ids' => [$cat1->id, $cat2->id]],
            'sort_order' => 0, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/homepage');

        $names = collect($response->json('data.0.content.categories'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Cake', 'Cookies'], $names);
    }

    public function test_public_homepage_resolves_product_references_by_category(): void
    {
        $cat = ProductCategory::create(['name' => 'Cake', 'slug' => 'cake-'.uniqid(), 'is_active' => true]);
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'category_id' => $cat->id, 'name' => 'Black Forest', 'slug' => 'bf-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        CmsHomepageBlock::create([
            'type' => 'product', 'content' => ['category_id' => $cat->id, 'limit' => 4],
            'sort_order' => 0, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/homepage');

        $names = collect($response->json('data.0.content.products'))->pluck('name')->all();
        $this->assertSame(['Black Forest'], $names);
    }

    public function test_public_homepage_requires_no_authentication(): void
    {
        CmsHomepageBlock::create(['type' => 'text', 'content' => ['body' => 'X'], 'sort_order' => 0, 'is_active' => true]);

        Auth::guard('web')->logout();
        $this->getJson('/api/v1/homepage')->assertOk();
    }
}
