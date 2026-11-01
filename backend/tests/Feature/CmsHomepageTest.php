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
            // Banner contract (Improvement 13): 10 MiB per homepage_block.
            // 11 MiB must be rejected; 4 MiB is legal now.
            'image' => UploadedFile::fake()->image('huge.jpg')->size(11264), // 11MB > 10MB limit
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

    public function test_native_contact_and_marketplace_blocks_validate_safe_destinations(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'social_media', 'content' => ['layout' => 'cards', 'platforms' => [
                'instagram' => ['enabled' => true, 'url' => 'https://instagram.com/primeclassy'],
                'tiktok' => ['enabled' => false, 'url' => null],
                'facebook' => ['enabled' => false, 'url' => null],
            ]],
        ])->assertCreated();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'whatsapp_chat', 'content' => ['phone' => '628123456789', 'message' => 'Halo', 'button_label' => 'Chat', 'mode' => 'floating'],
        ])->assertCreated();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'marketplace_links', 'content' => ['layout' => 'cards', 'platforms' => [
                'tokopedia' => ['enabled' => true, 'url' => 'https://tokopedia.com/primeclassy'],
                'shopee' => ['enabled' => true, 'url' => 'https://shopee.co.id/primeclassy'],
            ]],
        ])->assertCreated();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'marketplace_links', 'content' => ['layout' => 'cards', 'platforms' => [
                'tokopedia' => ['enabled' => true, 'url' => 'https://tokopedia.example/typo'],
                'shopee' => ['enabled' => false, 'url' => null],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('content.platforms.tokopedia.url');
    }

    public function test_platform_flags_sent_as_form_strings_are_accepted_on_create_update_and_reload(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // Multipart FormData serialises booleans as the strings "true"/"false".
        $id = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'social_media',
            'content' => ['layout' => 'cards', 'platforms' => [
                'instagram' => ['enabled' => 'true', 'url' => 'https://www.instagram.com/primeclassy'],
                'tiktok' => ['enabled' => 'false', 'url' => 'https://vt.tiktok.com/abc'],
                'facebook' => ['enabled' => '1', 'url' => 'https://facebook.com'],
            ]],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $platforms = CmsHomepageBlock::findOrFail($id)->content['platforms'];
        $this->assertTrue($platforms['instagram']['enabled']);
        $this->assertFalse($platforms['tiktok']['enabled']);
        $this->assertTrue($platforms['facebook']['enabled']);
        $this->assertSame('https://facebook.com', $platforms['facebook']['url']);

        $this->actingAs($superAdmin)->post("/api/v1/cms/homepage-blocks/{$id}", [
            '_method' => 'PATCH',
            'content' => ['platforms' => [
                'instagram' => ['enabled' => 'false', 'url' => 'https://www.instagram.com/primeclassy'],
                'tiktok' => ['enabled' => 'true', 'url' => 'https://www.tiktok.com/@primeclassy'],
                'facebook' => ['enabled' => '0', 'url' => 'https://m.facebook.com/primeclassy'],
            ]],
        ], ['Accept' => 'application/json'])->assertOk();

        $platforms = CmsHomepageBlock::findOrFail($id)->content['platforms'];
        $this->assertFalse($platforms['instagram']['enabled']);
        $this->assertTrue($platforms['tiktok']['enabled']);
        $public = $this->getJson('/api/v1/homepage')->json('data.0.content.platforms');
        $this->assertTrue($public['tiktok']['enabled']);
        $this->assertFalse($public['instagram']['enabled']);

        $mp = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'marketplace_links',
            'content' => ['layout' => 'cards', 'platforms' => [
                'tokopedia' => ['enabled' => 'true', 'url' => 'https://www.tokopedia.com/primeclassy?source=a&x=1'],
                'shopee' => ['enabled' => 'false', 'url' => 'https://shopee.co.id/primeclassy'],
            ]],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertSame(
            'https://www.tokopedia.com/primeclassy?source=a&x=1',
            CmsHomepageBlock::findOrFail($mp)->content['platforms']['tokopedia']['url'],
        );
    }

    public function test_platform_validation_still_rejects_bad_flags_and_unsafe_urls(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $post = fn (array $platforms) => $this->actingAs($superAdmin)->postJson('/api/v1/cms/homepage-blocks', [
            'type' => 'social_media', 'content' => ['layout' => 'cards', 'platforms' => $platforms],
        ]);
        $ok = ['enabled' => false, 'url' => null];

        $post(['instagram' => ['enabled' => 'maybe', 'url' => null], 'tiktok' => $ok, 'facebook' => $ok])
            ->assertStatus(422)->assertJsonValidationErrors('content.platforms.instagram.enabled');
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://instagram.com/x', 'https://instagram.com.evil.test/x', 'https://evilinstagram.com/x'] as $bad) {
            $post(['instagram' => ['enabled' => true, 'url' => $bad], 'tiktok' => $ok, 'facebook' => $ok])
                ->assertStatus(422)->assertJsonValidationErrors('content.platforms.instagram.url');
        }
    }

    public function test_public_category_block_follows_cms_id_order_and_skips_inactive_or_missing(): void
    {
        $ids = [];
        foreach (['Cookies', 'Cake', 'Hampers', 'Lainnya', 'Choco'] as $name) {
            $ids[] = ProductCategory::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'is_active' => true])->id;
        }
        [$cookies, $cake, $hampers, $lainnya, $choco] = $ids;
        $block = CmsHomepageBlock::create(['type' => 'category', 'content' => ['category_ids' => [$cookies, $choco, $cake, $lainnya, $hampers]], 'sort_order' => 0, 'is_active' => true]);
        $names = fn () => collect($this->getJson('/api/v1/homepage')->json('data.0.content.categories'))->pluck('name')->all();

        $this->assertSame(['Cookies', 'Choco', 'Cake', 'Lainnya', 'Hampers'], $names());

        $block->update(['content' => ['category_ids' => [$hampers, $cookies, $choco, $cake, $lainnya]]]);
        $this->assertSame(['Hampers', 'Cookies', 'Choco', 'Cake', 'Lainnya'], $names());

        ProductCategory::whereKey($choco)->update(['is_active' => false]);
        $block->update(['content' => ['category_ids' => [$cookies, $choco, $cake, 99999, $cake, $hampers]]]);
        $this->assertSame(['Cookies', 'Cake', 'Hampers'], $names());
    }

    public function test_admin_category_order_round_trips_through_save_reload_and_public_homepage(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ids = [];
        foreach (['Cookies', 'Cake', 'Hampers', 'Lainnya', 'Choco'] as $name) {
            $ids[$name] = ProductCategory::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'is_active' => true])->id;
        }
        $order = [$ids['Cookies'], $ids['Choco'], $ids['Cake'], $ids['Lainnya'], $ids['Hampers']];

        // Multipart form fields, as the admin form submits them.
        $blockId = $this->actingAs($superAdmin)->post('/api/v1/cms/homepage-blocks', [
            'type' => 'category', 'content' => ['category_ids' => array_map('strval', $order)],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->assertSame($order, $this->getJson("/api/v1/cms/homepage-blocks")->json('data.0.content.category_ids'));
        $public = fn () => collect($this->getJson('/api/v1/homepage')->json('data.0.content.categories'))->pluck('id')->all();
        $this->assertSame($order, $public());
        $this->assertSame($order, $public()); // refresh

        $alt = [$ids['Hampers'], $ids['Cookies'], $ids['Choco'], $ids['Cake'], $ids['Lainnya']];
        $this->actingAs($superAdmin)->post("/api/v1/cms/homepage-blocks/{$blockId}", [
            '_method' => 'PATCH', 'content' => ['category_ids' => array_map('strval', $alt)],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($alt, $this->getJson('/api/v1/cms/homepage-blocks')->json('data.0.content.category_ids'));
        $this->assertSame($alt, $public());
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
        $this->assertSame(['Cake', 'Cookies'], $names);

        $block = CmsHomepageBlock::query()->firstOrFail();
        $block->update(['content' => ['category_ids' => [$cat2->id, $cat1->id]]]);
        $reordered = collect($this->getJson('/api/v1/homepage')->json('data.0.content.categories'))->pluck('name')->all();
        $this->assertSame(['Cookies', 'Cake'], $reordered);
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
