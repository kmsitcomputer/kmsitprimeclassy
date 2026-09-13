<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\EnvFileWriter;
use App\Support\InstallLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private function validDbPayload(array $overrides = []): array
    {
        return array_merge([
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'primeclassy_testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ], $overrides);
    }

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner', 'email' => 'owner@example.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ], $overrides);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/installed.lock'));
        @unlink(EnvFileWriter::path());
        parent::tearDown();
    }

    public function test_status_reports_not_installed_before_anything_runs(): void
    {
        $this->getJson('/api/v1/install/status')
            ->assertOk()
            ->assertJsonPath('data.installed', false)
            ->assertJsonPath('data.has_super_admin', false);
    }

    public function test_requirements_reports_php_version_and_extensions(): void
    {
        $this->getJson('/api/v1/install/requirements')
            ->assertOk()
            ->assertJsonPath('data.php.ok', true)
            ->assertJsonStructure(['data' => ['php', 'extensions', 'permissions', 'all_ok']]);
    }

    public function test_database_test_rejects_bad_credentials(): void
    {
        $this->postJson('/api/v1/install/database/test', $this->validDbPayload(['password' => 'definitely-wrong']))
            ->assertStatus(422);
    }

    public function test_database_test_accepts_correct_credentials_without_persisting_anything(): void
    {
        $this->postJson('/api/v1/install/database/test', $this->validDbPayload())->assertOk();

        $this->assertFileDoesNotExist(EnvFileWriter::path());
    }

    public function test_database_save_persists_to_the_env_file_not_the_real_project_env(): void
    {
        $this->postJson('/api/v1/install/database/save', $this->validDbPayload())->assertOk();

        $this->assertFileExists(EnvFileWriter::path());
        $written = file_get_contents(EnvFileWriter::path());
        $this->assertStringContainsString('DB_DATABASE='.env('DB_DATABASE'), $written);
    }

    public function test_configure_app_writes_frontend_url_derived_stateful_domain(): void
    {
        $this->postJson('/api/v1/install/configure-app', [
            'app_name' => 'Prime Classy',
            'app_url' => 'https://shop.example.com',
            'frontend_url' => 'https://shop.example.com',
        ])->assertOk();

        $written = file_get_contents(EnvFileWriter::path());
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS=shop.example.com', $written);
        $this->assertStringContainsString('FRONTEND_URLS=https://shop.example.com', $written);
        $this->assertStringNotContainsString('SESSION_DOMAIN=', $written);
    }

    public function test_run_migrates_seeds_and_creates_the_super_admin(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'owner@example.com');

        $this->assertTrue(Role::query()->where('slug', 'super_admin')->exists());

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('super_admin', $user->role->slug);
        $this->assertSame('active', $user->status);

        // Not locked yet — that's a deliberately separate, later step.
        $this->assertFalse(InstallLock::isInstalled());
    }

    public function test_run_refuses_a_second_super_admin(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();

        $this->postJson('/api/v1/install/run', $this->adminPayload(['email' => 'second@example.com']))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_lock_requires_a_super_admin_to_exist_first(): void
    {
        $this->postJson('/api/v1/install/lock')->assertStatus(422);
        $this->assertFalse(InstallLock::isInstalled());
    }

    public function test_finalize_and_lock_complete_the_installation(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();

        $this->postJson('/api/v1/install/finalize')
            ->assertOk()
            ->assertJsonPath('data.has_super_admin', true);

        $this->postJson('/api/v1/install/lock')->assertOk();

        $this->assertTrue(InstallLock::isInstalled());
        $this->getJson('/api/v1/install/status')->assertJsonPath('data.installed', true);
    }

    public function test_locked_installer_rejects_every_write_endpoint_forever(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();
        $this->postJson('/api/v1/install/lock')->assertOk();

        $this->postJson('/api/v1/install/database/save', $this->validDbPayload())->assertStatus(403);
        $this->postJson('/api/v1/install/configure-app', [
            'app_name' => 'x', 'app_url' => 'https://a.test', 'frontend_url' => 'https://b.test',
        ])->assertStatus(403);
        $this->postJson('/api/v1/install/run', $this->adminPayload(['email' => 'third@example.com']))->assertStatus(403);
        $this->postJson('/api/v1/install/finalize')->assertStatus(403);
        $this->postJson('/api/v1/install/lock')->assertStatus(403);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_database_and_app_config_refuse_to_run_once_a_super_admin_exists_even_without_the_lock_file(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();

        // Defense in depth: even if the lock file were somehow missing,
        // these two must never repoint a database/app that already has a
        // Super Admin in it.
        @unlink(storage_path('framework/testing/installed.lock'));

        $this->postJson('/api/v1/install/database/save', $this->validDbPayload())->assertStatus(403);
        $this->postJson('/api/v1/install/configure-app', [
            'app_name' => 'x', 'app_url' => 'https://a.test', 'frontend_url' => 'https://b.test',
        ])->assertStatus(403);
    }

    /**
     * The real deadlock this solves: a fresh deployment's FRONTEND_URLS is
     * still a placeholder (or .env doesn't exist yet at all) — without
     * reflecting the actual Origin back pre-install, the wizard's own first
     * request would already be rejected by CORS, and the "Application" step
     * that lets the operator type in their real frontend URL would be
     * unreachable to ever fix that.
     */
    public function test_cors_reflects_the_requesting_origin_before_install_regardless_of_frontend_urls(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://totally-unconfigured-domain.test'])
            ->getJson('/api/v1/install/status');

        $response->assertOk();
        $this->assertSame('https://totally-unconfigured-domain.test', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_stops_reflecting_arbitrary_origins_once_installed(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();
        $this->postJson('/api/v1/install/lock')->assertOk();

        $response = $this->withHeaders(['Origin' => 'https://some-other-domain.test'])
            ->getJson('/api/v1/install/status');

        $response->assertOk();
        $this->assertNotSame('https://some-other-domain.test', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The other half of the same deadlock CORS reflection solves: even once
     * CORS lets a cross-origin request through, Sanctum only wraps it with
     * session/CSRF handling when the request's Origin/Referer host matches
     * config('sanctum.stateful') — an unconfigured frontend domain would
     * otherwise be treated as stateless during install, then suddenly
     * "become" stateful (and fail CSRF against a session that was never
     * actually started) the moment the "Application" step writes the real
     * SANCTUM_STATEFUL_DOMAINS. Reflecting the host pre-install avoids that
     * flip entirely.
     */
    public function test_sanctum_stateful_domain_reflects_the_requesting_origin_before_install(): void
    {
        // A real browser sends both on a fetch/XHR call, from the same page — matching that here.
        $this->withHeaders([
            'Origin' => 'https://totally-unconfigured-domain.test',
            'Referer' => 'https://totally-unconfigured-domain.test/install',
        ])->getJson('/api/v1/install/status')->assertOk();

        $this->assertSame(['totally-unconfigured-domain.test'], config('sanctum.stateful'));
    }

    public function test_sanctum_stateful_domain_stops_reflecting_once_installed(): void
    {
        $this->postJson('/api/v1/install/run', $this->adminPayload())->assertCreated();
        $this->postJson('/api/v1/install/lock')->assertOk();

        $configuredBefore = config('sanctum.stateful');

        $this->withHeaders(['Origin' => 'https://some-other-domain.test'])
            ->getJson('/api/v1/install/status')
            ->assertOk();

        $this->assertSame($configuredBefore, config('sanctum.stateful'));
    }

    /**
     * The actual real-world failure this closes: a stateful cross-subdomain
     * cookie round-trip (fetch csrf-cookie, then send it back as a header on
     * the next request) is fragile during the exact window the installer
     * runs in — APP_KEY/session driver can still be settling, SSL/SameSite
     * may not be fully sorted on a brand new host. A POST straight to an
     * install endpoint, from a recognized-as-stateful origin, with NO CSRF
     * token ever fetched at all, must still succeed — install routes are
     * exempted from CSRF entirely (see bootstrap/app.php).
     */
    public function test_install_endpoints_never_require_a_csrf_token_even_when_the_origin_is_stateful(): void
    {
        $this->withHeaders([
            'Origin' => 'https://totally-unconfigured-domain.test',
            'Referer' => 'https://totally-unconfigured-domain.test/install',
        ])->postJson('/api/v1/install/database/test', $this->validDbPayload())->assertOk();
    }

    public function test_run_validates_input(): void
    {
        $this->postJson('/api/v1/install/run', [
            'name' => '', 'email' => 'not-an-email', 'phone' => '',
            'password' => 'short', 'password_confirmation' => 'mismatch',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'password']);
    }
}
