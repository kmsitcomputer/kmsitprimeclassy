<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The generic media abstraction (Blueprint §Media Management) — upload/replace/delete, MIME/extension/size/dimension validation, secure filenames. */
class MediaSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    public function test_super_admin_can_upload_media_and_receives_a_resolved_url(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            'collection' => 'cms_article_cover',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.url'));
        $this->assertSame(800, $response->json('data.width'));
        $this->assertSame(600, $response->json('data.height'));
        $this->assertSame('cms_article_cover', $response->json('data.collection'));

        $media = Media::first();
        Storage::disk('public')->assertExists($media->path);

        // Secure filename — never the client's original name.
        $this->assertStringNotContainsString('cover.jpg', $media->path);
    }

    public function test_media_upload_rejects_a_disallowed_mime_type(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('malware.php', 10, 'application/x-php'),
            'collection' => 'cms_article_cover',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_media_upload_rejects_a_file_disguised_as_an_image(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // Forged extension + a client-declared image mime, but the actual bytes are not a decodable image.
        $fakeImage = UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image"; ?>');

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => $fakeImage,
            'collection' => 'cms_article_cover',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_media_upload_rejects_a_file_over_the_collections_size_limit(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('huge.jpg')->size(5000), // homepage_block caps at 2MB
            'collection' => 'homepage_block',
        ]);

        $response->assertStatus(422);
    }

    public function test_media_upload_rejects_dimensions_over_the_collections_limit(): void
    {
        // A tiny cap keeps the fake image itself small (a real 4000px+ GD
        // bitmap would exhaust the test process's memory limit).
        config(['media.collections.cms_article_cover.max_width' => 100]);
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('huge.jpg', 200, 200),
            'collection' => 'cms_article_cover',
        ]);

        $response->assertStatus(422);
    }

    public function test_media_upload_rejects_an_unknown_collection(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'collection' => 'not_a_real_collection',
        ]);

        $response->assertStatus(422);
    }

    public function test_only_super_admin_and_agen_may_upload_or_delete_media(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);

        // agen was deliberately widened alongside product-catalog management —
        // it can upload to shared collections too (e.g. product/CMS images).
        $this->actingAs($agen)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'collection' => 'cms_article_cover',
        ])->assertCreated();

        $this->actingAs($sales)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'collection' => 'cms_article_cover',
        ])->assertStatus(403);
    }

    public function test_every_role_may_upload_and_delete_their_own_avatar(): void
    {
        $sales = User::factory()->sales()->create();
        $sales->update(['agent_id' => User::factory()->agen()->create()->id]);

        $upload = $this->actingAs($sales)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('me.jpg'),
            'collection' => 'user_avatar',
        ]);
        if ($upload->status() !== 201) {
            fwrite(STDERR, "DEBUG BODY: ".$upload->getContent()."\n");
        }
        $upload->assertCreated();

        $this->actingAs($sales)->delete('/api/v1/media/'.$upload->json('data.id'))->assertOk();
    }

    public function test_deleting_media_removes_both_the_row_and_the_file(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $upload = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'collection' => 'cms_article_cover',
        ]);
        $mediaId = $upload->json('data.id');
        $path = Media::findOrFail($mediaId)->path;

        $this->actingAs($superAdmin)->delete("/api/v1/media/{$mediaId}")->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_media_library_lists_uploads_and_can_filter_by_collection_super_admin_only(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('a.jpg'), 'collection' => 'cms_article_cover',
        ])->assertCreated();
        $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('b.jpg'), 'collection' => 'cms_content',
        ])->assertCreated();

        $all = $this->actingAs($superAdmin)->getJson('/api/v1/media');
        $all->assertOk();
        $this->assertCount(2, $all->json('data'));

        $filtered = $this->actingAs($superAdmin)->getJson('/api/v1/media?collection=cms_content');
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('cms_content', $filtered->json('data.0.collection'));

        $this->actingAs($agen)->getJson('/api/v1/media')->assertStatus(403);
    }
}
