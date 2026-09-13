<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Website Settings (Blueprint §Website Settings) — DB-backed, cached read, never hard-coded. */
class WebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    public function test_public_settings_endpoint_returns_defaults_before_anything_is_configured(): void
    {
        $response = $this->getJson('/api/v1/settings');
        $response->assertOk();
        $this->assertSame('Prime Classy Cake & Cookies', $response->json('data')['site_title']);
    }

    /**
     * The SPA's header/logo chrome calls this endpoint unconditionally on
     * every page load, including the installer wizard's own pages — before
     * any database has even been configured on a genuinely fresh deployment
     * (no .env, DB_CONNECTION still whatever the framework default is). This
     * must degrade to plain defaults instantly, never a 500 from a query
     * against a table (or a whole database) that doesn't exist yet.
     */
    public function test_public_settings_endpoint_survives_a_completely_missing_settings_table(): void
    {
        Schema::dropIfExists('settings');

        $response = $this->getJson('/api/v1/settings');
        $response->assertOk();
        $this->assertSame('Prime Classy Cake & Cookies', $response->json('data')['site_title']);
        $this->assertNull($response->json('data')['logo_url']);
    }

    public function test_super_admin_can_update_settings_and_the_public_endpoint_reflects_it_immediately(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->putJson('/api/v1/admin/settings', [
            'site_title' => 'Toko Kue Prima',
            'site_slogan' => 'Kue Lebaran Terenak',
            'site_contact_email' => 'halo@primeclassy.test',
            'site_social' => ['instagram' => 'https://instagram.com/primeclassy'],
        ]);

        $response->assertOk();
        $this->assertSame('Toko Kue Prima', $response->json('data')['site_title']);

        // The DB is the source of truth — a fresh public read (cache invalidated on write) reflects it too.
        $public = $this->getJson('/api/v1/settings');
        $public->assertOk();
        $this->assertSame('Toko Kue Prima', $public->json('data')['site_title']);
        $this->assertSame('https://instagram.com/primeclassy', $public->json('data')['site_social']['instagram']);

        $this->assertDatabaseHas('settings', ['key' => 'site_title', 'value' => 'Toko Kue Prima']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.changed']);
    }

    public function test_public_settings_read_is_cached_but_still_invalidated_by_a_write(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->getJson('/api/v1/settings')->assertOk();
        $this->assertTrue(Cache::has('website_settings.public'));

        $this->actingAs($superAdmin)->putJson('/api/v1/admin/settings', ['site_title' => 'Baru'])->assertOk();

        // Cache was cleared by the write, not left stale.
        $refreshed = $this->getJson('/api/v1/settings');
        $this->assertSame('Baru', $refreshed->json('data')['site_title']);
    }

    public function test_super_admin_can_upload_a_logo_and_it_resolves_to_a_url(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $upload = $this->actingAs($superAdmin)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('logo.png', 300, 300),
            'collection' => 'site_logo',
        ]);
        $upload->assertCreated();
        $mediaId = $upload->json('data.id');

        $response = $this->actingAs($superAdmin)->putJson('/api/v1/admin/settings', [
            'site_logo_media_id' => $mediaId,
        ]);
        $response->assertOk();
        $this->assertNotNull($response->json('data.logo_url'));
    }

    public function test_only_super_admin_may_write_settings_but_reading_public_settings_needs_no_auth(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($agen)->putJson('/api/v1/admin/settings', ['site_title' => 'Hack'])->assertStatus(403);
        $this->actingAs($agen)->getJson('/api/v1/admin/settings')->assertStatus(403);

        $this->getJson('/api/v1/settings')->assertOk();
    }

    public function test_updating_settings_never_breaks_the_frontend_by_rejecting_unknown_keys_silently(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->putJson('/api/v1/admin/settings', [
            'site_title' => 'OK',
            'not_a_real_key' => 'ignored',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('settings', ['key' => 'not_a_real_key']);
    }
}
