<?php

namespace Tests\Feature;

use App\Models\CmsHomepageBlock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Banner Slider block + the safe-link rule shared by Hero / CTA / Banner / Promotional / Image.
 * The slider lives entirely in `cms_homepage_blocks.content` (JSON) — no migration.
 */
class CmsBannerSliderTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/cms/homepage-blocks';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function slide(string $key, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'title' => 'Promo '.$key,
            'description' => 'Diskon akhir pekan',
            'button_label' => 'Lihat',
            'button_url' => '/produk',
            'overlay' => 'dark',
            'content_position' => 'left',
        ], $overrides);
    }

    private function content(array $slides, array $overrides = []): array
    {
        return array_merge([
            'layout' => 'contained',
            'autoplay' => '1',
            'interval_seconds' => 5,
            'show_arrows' => '1',
            'show_dots' => '0',
            'slides' => $slides,
        ], $overrides);
    }

    private function create(array $slides, array $images, array $contentOverrides = [])
    {
        return $this->actingAs($this->admin())->post(self::URL, [
            'type' => 'banner_slider',
            'content' => $this->content($slides, $contentOverrides),
            'slide_images' => $images,
        ]);
    }

    public function test_a_slider_with_desktop_and_mobile_images_is_stored_in_order_and_round_trips(): void
    {
        $response = $this->create(
            [$this->slide('slide-aaaa'), $this->slide('slide-bbbb', ['title' => 'Kedua'])],
            [
                'slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg', 1600, 600), 'mobile' => UploadedFile::fake()->image('am.jpg', 800, 800)],
                'slide-bbbb' => ['desktop' => UploadedFile::fake()->image('b.jpg', 1600, 600)],
            ],
        );

        $response->assertCreated();
        $block = CmsHomepageBlock::query()->findOrFail($response->json('data.id'));
        $slides = $block->content['slides'];
        $this->assertSame(['slide-aaaa', 'slide-bbbb'], array_column($slides, 'key'));
        $this->assertTrue($block->content['autoplay'] === '1' || $block->content['autoplay'] === true);
        $this->assertSame(5, (int) $block->content['interval_seconds']);
        Storage::disk('public')->assertExists($slides[0]['image_path']);
        Storage::disk('public')->assertExists($slides[0]['mobile_image_path']);
        Storage::disk('public')->assertExists($slides[1]['image_path']);
        $this->assertNull($slides[1]['mobile_image_path']);

        // Admin view: raw shape plus resolved URLs, so the editor can re-open the block.
        $this->assertNotNull($response->json('data.content.slides.0.image_url'));
        $this->assertNotNull($response->json('data.content.slides.0.mobile_image_url'));
        $this->assertNull($response->json('data.content.slides.1.mobile_image_url'));

        // Public feed: URLs only, never storage paths.
        $public = $this->getJson('/api/v1/homepage')->assertOk();
        $feed = collect($public->json('data'))->firstWhere('type', 'banner_slider');
        $this->assertNotNull($feed['content']['slides'][0]['image_url']);
        $this->assertArrayNotHasKey('image_path', $feed['content']['slides'][0]);
        $this->assertArrayNotHasKey('mobile_image_path', $feed['content']['slides'][0]);
        $this->assertSame('contained', $feed['content']['layout']);
    }

    public function test_every_slide_needs_a_desktop_image(): void
    {
        $this->create([$this->slide('slide-aaaa')], [])->assertStatus(422);
        $this->assertSame(0, CmsHomepageBlock::query()->count());
    }

    public function test_updating_reorders_slides_deletes_removed_slides_files_and_keeps_untouched_images(): void
    {
        $admin = $this->admin();
        $created = $this->actingAs($admin)->post(self::URL, [
            'type' => 'banner_slider',
            'content' => $this->content([$this->slide('slide-aaaa'), $this->slide('slide-bbbb'), $this->slide('slide-cccc')]),
            'slide_images' => [
                'slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg'), 'mobile' => UploadedFile::fake()->image('am.jpg')],
                'slide-bbbb' => ['desktop' => UploadedFile::fake()->image('b.jpg')],
                'slide-cccc' => ['desktop' => UploadedFile::fake()->image('c.jpg')],
            ],
        ])->assertCreated();
        $id = $created->json('data.id');
        $before = collect(CmsHomepageBlock::query()->findOrFail($id)->content['slides'])->keyBy('key');

        // Reorder to [c, a], delete b, drop a's mobile image, replace c's desktop image.
        $this->actingAs($admin)->post(self::URL."/{$id}", [
            '_method' => 'PATCH',
            'content' => $this->content([
                $this->slide('slide-cccc'),
                $this->slide('slide-aaaa', ['remove_mobile' => '1']),
            ]),
            'slide_images' => ['slide-cccc' => ['desktop' => UploadedFile::fake()->image('c2.jpg')]],
        ])->assertOk();

        $after = collect(CmsHomepageBlock::query()->findOrFail($id)->content['slides'])->keyBy('key');
        $this->assertSame(['slide-cccc', 'slide-aaaa'], array_column(CmsHomepageBlock::query()->findOrFail($id)->content['slides'], 'key'));

        Storage::disk('public')->assertMissing($before['slide-bbbb']['image_path']);            // deleted slide
        Storage::disk('public')->assertMissing($before['slide-aaaa']['mobile_image_path']);     // removed mobile
        Storage::disk('public')->assertMissing($before['slide-cccc']['image_path']);            // replaced
        Storage::disk('public')->assertExists($after['slide-cccc']['image_path']);
        $this->assertNotSame($before['slide-cccc']['image_path'], $after['slide-cccc']['image_path']);
        $this->assertSame($before['slide-aaaa']['image_path'], $after['slide-aaaa']['image_path']); // untouched
        Storage::disk('public')->assertExists($after['slide-aaaa']['image_path']);
        $this->assertNull($after['slide-aaaa']['mobile_image_path']);
    }

    public function test_a_client_can_never_supply_storage_paths(): void
    {
        $response = $this->create(
            [$this->slide('slide-aaaa', ['image_path' => '../../.env', 'mobile_image_path' => 'cms/other-block.jpg'])],
            ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')]],
        )->assertCreated();

        $slide = CmsHomepageBlock::query()->findOrFail($response->json('data.id'))->content['slides'][0];
        $this->assertStringStartsWith('cms/homepage-blocks/', $slide['image_path']);
        $this->assertNull($slide['mobile_image_path']);
    }

    public function test_deleting_the_block_removes_every_slide_file(): void
    {
        $admin = $this->admin();
        $created = $this->actingAs($admin)->post(self::URL, [
            'type' => 'banner_slider',
            'content' => $this->content([$this->slide('slide-aaaa')]),
            'slide_images' => ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg'), 'mobile' => UploadedFile::fake()->image('m.jpg')]],
        ])->assertCreated();
        $slide = CmsHomepageBlock::query()->findOrFail($created->json('data.id'))->content['slides'][0];

        $this->actingAs($admin)->deleteJson(self::URL.'/'.$created->json('data.id'))->assertOk();

        Storage::disk('public')->assertMissing($slide['image_path']);
        Storage::disk('public')->assertMissing($slide['mobile_image_path']);
    }

    public function test_slider_settings_are_validated(): void
    {
        $images = ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')]];
        $one = [$this->slide('slide-aaaa')];

        $this->create($one, $images, ['interval_seconds' => 2])->assertJsonValidationErrors(['content.interval_seconds']);
        $this->create($one, $images, ['interval_seconds' => 11])->assertJsonValidationErrors(['content.interval_seconds']);
        $this->create($one, $images, ['layout' => 'fullscreen'])->assertJsonValidationErrors(['content.layout']);
        $this->create([], [])->assertJsonValidationErrors(['content.slides']);
        $this->create([$this->slide('slide-aaaa', ['overlay' => 'neon'])], $images)->assertJsonValidationErrors(['content.slides.0.overlay']);
        $this->create([$this->slide('slide-aaaa', ['content_position' => 'top'])], $images)->assertJsonValidationErrors(['content.slides.0.content_position']);
        $this->create([$this->slide('x y')], $images)->assertJsonValidationErrors(['content.slides.0.key']);
        $this->create([$this->slide('slide-aaaa'), $this->slide('slide-aaaa')], $images)->assertJsonValidationErrors(['content.slides.0.key']);

        $tooMany = array_map(fn ($i) => $this->slide('slide-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)), range(1, 11));
        $this->create($tooMany, [])->assertJsonValidationErrors(['content.slides']);

        $this->assertSame(0, CmsHomepageBlock::query()->count());
    }

    public function test_slide_images_must_be_real_images(): void
    {
        $this->create(
            [$this->slide('slide-aaaa')],
            ['slide-aaaa' => ['desktop' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]],
        )->assertJsonValidationErrors(['slide_images.slide-aaaa.desktop']);

        $this->create(
            [$this->slide('slide-aaaa')],
            ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg'), 'mobile' => UploadedFile::fake()->create('m.svg', 10, 'image/svg+xml')]],
        )->assertJsonValidationErrors(['slide_images.slide-aaaa.mobile']);
    }

    public function test_only_safe_links_are_accepted_for_slides_and_other_link_fields(): void
    {
        $images = ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')]];

        foreach (['javascript:alert(1)', ' javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>1</script>', 'vbscript:x', '//evil.example/x', 'https://a.b/x y', "https://a.b/\x01"] as $bad) {
            $this->create([$this->slide('slide-aaaa', ['button_url' => $bad])], $images)
                ->assertJsonValidationErrors(['content.slides.0.button_url']);
        }

        foreach (['/produk/kue', '#kontak', 'https://primeclassy.id/promo?x=1&y=2', 'http://example.com', 'mailto:halo@primeclassy.id', 'tel:+628123456789'] as $good) {
            $this->create([$this->slide('slide-aaaa', ['button_url' => $good])], [
                'slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();
        }

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'hero', 'content' => ['heading' => 'x', 'cta_url' => 'javascript:alert(1)']])
            ->assertJsonValidationErrors(['content.cta_url']);
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'cta', 'content' => ['heading' => 'x', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']])
            ->assertJsonValidationErrors(['content.button_url']);
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'promotional', 'content' => ['heading' => 'x', 'url' => 'data:text/html,x']])
            ->assertJsonValidationErrors(['content.url']);
    }

    public function test_the_slider_does_not_accept_a_block_level_image(): void
    {
        $this->actingAs($this->admin())->post(self::URL, [
            'type' => 'banner_slider',
            'content' => $this->content([$this->slide('slide-aaaa')]),
            'slide_images' => ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')]],
            'image' => UploadedFile::fake()->image('stray.jpg'),
        ])->assertStatus(422);
    }

    public function test_hero_and_cta_presentation_options_are_optional_validated_and_backward_compatible(): void
    {
        $admin = $this->admin();

        // Legacy payloads (no new fields) still save.
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'hero', 'content' => ['heading' => 'Lama', 'cta_label' => 'Beli', 'cta_url' => '/produk']])->assertCreated();
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'cta', 'content' => ['heading' => 'Lama', 'button_label' => 'Go', 'button_url' => 'https://primeclassy.id']])->assertCreated();

        // New options are stored.
        $hero = $this->actingAs($admin)->postJson(self::URL, ['type' => 'hero', 'content' => [
            'heading' => 'Baru', 'image_fit' => 'contain', 'image_position' => 'top', 'overlay' => 'light', 'align' => 'center',
        ]])->assertCreated();
        $this->assertSame('contain', $hero->json('data.content.image_fit'));

        $cta = $this->actingAs($admin)->postJson(self::URL, ['type' => 'cta', 'content' => [
            'heading' => 'Baru', 'button_label' => 'Go', 'button_url' => '/x', 'description' => 'Deskripsi', 'theme' => 'sand', 'align' => 'left',
        ]])->assertCreated();
        $this->assertSame('sand', $cta->json('data.content.theme'));

        // And are rejected when out of range.
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'hero', 'content' => ['heading' => 'x', 'image_fit' => 'stretch']])->assertJsonValidationErrors(['content.image_fit']);
        $this->actingAs($admin)->postJson(self::URL, ['type' => 'cta', 'content' => ['heading' => 'x', 'button_label' => 'g', 'button_url' => '/x', 'theme' => 'neon']])->assertJsonValidationErrors(['content.theme']);
    }

    public function test_the_slider_moves_with_other_blocks_through_the_existing_reorder_endpoint(): void
    {
        $admin = $this->admin();
        $text = $this->actingAs($admin)->postJson(self::URL, ['type' => 'text', 'content' => ['body' => 'Halo']])->json('data.id');
        $slider = $this->create([$this->slide('slide-aaaa')], ['slide-aaaa' => ['desktop' => UploadedFile::fake()->image('a.jpg')]])->json('data.id');

        $this->actingAs($admin)->patchJson(self::URL.'/reorder', ['block_ids' => [$slider, $text]])->assertOk();

        $types = collect($this->getJson('/api/v1/homepage')->json('data'))->pluck('type')->all();
        $this->assertSame(['banner_slider', 'text'], $types);
    }
}
