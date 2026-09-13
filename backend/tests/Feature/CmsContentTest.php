<?php

namespace Tests\Feature;

use App\Models\CmsArticle;
use App\Models\CmsPage;
use App\Models\Language;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Articles/News + static Pages (Blueprint: "pages, articles, news, CMS content") — CKEditor 5-authored body content, sanitized server-side. */
class CmsContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        Storage::fake('public');
    }

    private function uploadCover(User $actor, string $collection = 'cms_article_cover'): int
    {
        $response = $this->actingAs($actor)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            'collection' => $collection,
        ]);
        $response->assertCreated();

        return $response->json('data.id');
    }

    /* ---------------------------------------------------------------
     * Articles/News
     * ------------------------------------------------------------- */

    public function test_super_admin_can_create_an_article_with_a_cover_image_and_sanitized_body(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();
        $mediaId = $this->uploadCover($superAdmin);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/cms/articles', [
            'type' => 'article',
            'slug' => 'resep-kue-lebaran',
            'status' => 'published',
            'published_at' => now()->toDateTimeString(),
            'cover_media_id' => $mediaId,
            'translations' => [
                ['language_id' => $indonesian->id, 'title' => 'Resep Kue Lebaran', 'excerpt' => 'Ringkasan', 'body' => '<p>Halo</p><script>alert(1)</script>'],
            ],
        ]);

        $response->assertCreated();
        $this->assertStringContainsString('<p>Halo</p>', $response->json('data.translations.0.body'));
        $this->assertStringNotContainsString('<script>', $response->json('data.translations.0.body'));
        $this->assertNotNull($response->json('data.cover_image_url'));

        $this->assertDatabaseHas('media', ['id' => $mediaId, 'mediable_type' => CmsArticle::class]);
    }

    public function test_articles_endpoint_only_returns_published_rows_and_can_filter_by_type(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/articles', [
            'type' => 'article', 'slug' => 'artikel-draft', 'status' => 'draft',
            'translations' => [['language_id' => $indonesian->id, 'title' => 'Draft', 'body' => '<p>Draft</p>']],
        ])->assertCreated();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/articles', [
            'type' => 'news', 'slug' => 'berita-terbit', 'status' => 'published', 'published_at' => now(),
            'translations' => [['language_id' => $indonesian->id, 'title' => 'Berita', 'body' => '<p>Berita</p>']],
        ])->assertCreated();

        $publicList = $this->getJson('/api/v1/articles');
        $publicList->assertOk();
        $slugs = collect($publicList->json('data'))->pluck('slug')->all();
        $this->assertContains('berita-terbit', $slugs);
        $this->assertNotContains('artikel-draft', $slugs);

        $newsOnly = $this->getJson('/api/v1/articles?type=news');
        $newsOnly->assertOk();
        $this->assertCount(1, $newsOnly->json('data'));
    }

    public function test_only_super_admin_may_write_articles_but_reading_published_ones_is_public(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $indonesian = Language::where('code', 'id')->firstOrFail();

        $this->actingAs($agen)->postJson('/api/v1/cms/articles', [
            'type' => 'article', 'slug' => 'coba', 'translations' => [['language_id' => $indonesian->id, 'title' => 'X', 'body' => 'Y']],
        ])->assertStatus(403);

        $this->getJson('/api/v1/articles')->assertOk();
    }

    public function test_updating_an_articles_cover_deletes_the_old_media_row_and_file(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();
        $firstMediaId = $this->uploadCover($superAdmin);

        $create = $this->actingAs($superAdmin)->postJson('/api/v1/cms/articles', [
            'type' => 'article', 'slug' => 'ganti-cover', 'cover_media_id' => $firstMediaId,
            'translations' => [['language_id' => $indonesian->id, 'title' => 'T', 'body' => '<p>B</p>']],
        ]);
        $articleId = $create->json('data.id');
        $firstPath = Media::findOrFail($firstMediaId)->path;

        $secondMediaId = $this->uploadCover($superAdmin);
        $this->actingAs($superAdmin)->patchJson("/api/v1/cms/articles/{$articleId}", [
            'cover_media_id' => $secondMediaId,
        ])->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $firstMediaId]);
        Storage::disk('public')->assertMissing($firstPath);
        $this->assertDatabaseHas('media', ['id' => $secondMediaId, 'mediable_id' => $articleId]);
    }

    public function test_deleting_an_article_deletes_its_cover_media_too(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();
        $mediaId = $this->uploadCover($superAdmin);

        $create = $this->actingAs($superAdmin)->postJson('/api/v1/cms/articles', [
            'type' => 'article', 'slug' => 'akan-dihapus', 'cover_media_id' => $mediaId,
            'translations' => [['language_id' => $indonesian->id, 'title' => 'T', 'body' => '<p>B</p>']],
        ]);
        $articleId = $create->json('data.id');

        $this->actingAs($superAdmin)->deleteJson("/api/v1/cms/articles/{$articleId}")->assertOk();

        $this->assertDatabaseMissing('cms_articles', ['id' => $articleId]);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
    }

    /* ---------------------------------------------------------------
     * Static pages
     * ------------------------------------------------------------- */

    public function test_super_admin_can_create_a_page_and_its_body_is_sanitized(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/cms/pages', [
            'slug' => 'tentang-kami', 'status' => 'published', 'published_at' => now(),
            'translations' => [
                ['language_id' => $indonesian->id, 'title' => 'Tentang Kami', 'body' => '<p onclick="steal()">Halo</p>', 'seo_title' => 'Tentang'],
            ],
        ]);

        $response->assertCreated();
        $this->assertStringNotContainsString('onclick', $response->json('data.translations.0.body'));
        $this->assertStringContainsString('<p>Halo</p>', $response->json('data.translations.0.body'));
    }

    public function test_super_admin_can_create_a_page_with_a_cover_image_and_replace_it_on_update(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();
        $mediaId = $this->uploadCover($superAdmin, 'cms_page_cover');

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/cms/pages', [
            'slug' => 'about-us', 'status' => 'published', 'cover_media_id' => $mediaId,
            'translations' => [['language_id' => $indonesian->id, 'title' => 'About', 'body' => 'x']],
        ]);
        $response->assertCreated();
        $this->assertNotNull($response->json('data.cover_image_url'));

        $page = CmsPage::where('slug', 'about-us')->firstOrFail();
        $this->assertDatabaseHas('media', ['id' => $mediaId, 'mediable_type' => CmsPage::class, 'mediable_id' => $page->id]);

        $newMediaId = $this->uploadCover($superAdmin, 'cms_page_cover');
        $this->actingAs($superAdmin)->patchJson("/api/v1/cms/pages/{$page->id}", ['cover_media_id' => $newMediaId])->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        $this->assertDatabaseHas('media', ['id' => $newMediaId, 'mediable_type' => CmsPage::class, 'mediable_id' => $page->id]);
    }

    public function test_pages_are_only_publicly_readable_once_published(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $indonesian = Language::where('code', 'id')->firstOrFail();

        $this->actingAs($superAdmin)->postJson('/api/v1/cms/pages', [
            'slug' => 'draft-page', 'status' => 'draft',
            'translations' => [['language_id' => $indonesian->id, 'title' => 'Draft', 'body' => 'x']],
        ])->assertCreated();

        $this->getJson('/api/v1/pages/draft-page')->assertStatus(404);

        CmsPage::where('slug', 'draft-page')->update(['status' => 'published']);
        $this->getJson('/api/v1/pages/draft-page')->assertOk();
    }

    public function test_only_super_admin_may_write_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->update(['agent_id' => User::factory()->agen()->create()->id]);
        $indonesian = Language::where('code', 'id')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/v1/cms/pages', [
            'slug' => 'coba', 'translations' => [['language_id' => $indonesian->id, 'title' => 'X']],
        ])->assertStatus(403);
    }
}
