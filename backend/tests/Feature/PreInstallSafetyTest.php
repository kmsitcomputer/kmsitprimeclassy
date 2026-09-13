<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A handful of public API routes are reachable before the installer wizard
 * has migrated anything — direct HTTP requests (bots, monitoring) or the
 * SPA's own chrome can hit them on a completely fresh, zero-table database.
 * Each of these mirrors WebsiteSettingsTest's missing-`settings`-table case:
 * drop the table the endpoint depends on and confirm it degrades to a
 * clean, deliberate response (an empty list/page, or a 404) instead of an
 * uncaught QueryException — see SafeSchema's own docblock for why "no
 * table" and "no DB connection at all" are treated identically.
 */
class PreInstallSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // MySQL DDL commits implicitly, so a dropped table cannot be restored
        // by RefreshDatabase's transaction rollback. Force a fresh migration
        // before the next test; TestCase's database-name guard guarantees this
        // can only target an isolated test database.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /** Several of these tables are FK-referenced by others still present in the test schema — disabling checks around the drop mirrors a genuinely fresh DB, which has no rows or constraints to conflict with in the first place. */
    private function dropTable(string $table): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }

    public function test_categories_survive_a_missing_product_categories_table(): void
    {
        $this->dropTable('product_categories');

        $response = $this->getJson('/api/v1/categories');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_products_index_and_show_survive_a_missing_products_table(): void
    {
        $this->dropTable('products');

        $index = $this->getJson('/api/v1/products');
        $index->assertOk();
        $this->assertSame([], $index->json('data'));

        $show = $this->getJson('/api/v1/products/anything');
        $show->assertNotFound();
    }

    public function test_homepage_survives_a_missing_cms_homepage_blocks_table(): void
    {
        $this->dropTable('cms_homepage_blocks');

        $response = $this->getJson('/api/v1/homepage');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_articles_index_and_show_survive_a_missing_cms_articles_table(): void
    {
        $this->dropTable('cms_articles');

        $index = $this->getJson('/api/v1/articles');
        $index->assertOk();
        $this->assertSame([], $index->json('data'));

        $show = $this->getJson('/api/v1/articles/anything');
        $show->assertNotFound();
    }

    public function test_page_show_survives_a_missing_cms_pages_table(): void
    {
        $this->dropTable('cms_pages');

        $this->getJson('/api/v1/pages/anything')->assertNotFound();
    }

    public function test_languages_survive_a_missing_languages_table(): void
    {
        $this->dropTable('languages');

        $response = $this->getJson('/api/v1/languages');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_agents_directory_survives_a_missing_agent_profiles_table(): void
    {
        $this->dropTable('agent_profiles');

        $response = $this->getJson('/api/v1/agents');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_checkout_steps_survive_missing_payment_methods_and_shipping_providers_tables(): void
    {
        $this->dropTable('payment_methods');
        $this->dropTable('shipping_providers');

        $response = $this->getJson('/api/v1/checkout/steps');
        $response->assertOk();
        $this->assertSame([], $response->json('data.payment_methods'));
        $this->assertSame([], $response->json('data.shipping_methods'));
        $this->assertFalse($response->json('data.shipping_enabled'));
    }

    public function test_payment_webhook_survives_a_missing_payment_methods_table(): void
    {
        $this->dropTable('payment_methods');

        $response = $this->postJson('/api/v1/webhooks/payment/midtrans', []);
        $response->assertNotFound();
    }

    public function test_referral_preview_survives_a_missing_users_table(): void
    {
        $this->dropTable('users');

        $response = $this->getJson('/api/v1/referral/ANYCODE');
        $response->assertStatus(422);
    }

    public function test_region_lookups_survive_missing_region_tables(): void
    {
        $this->dropTable('provinces');
        $this->dropTable('regencies');
        $this->dropTable('districts');

        $this->getJson('/api/v1/regions/provinces')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/regions/regencies?province_id=1')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/regions/districts?regency_id=1')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/regions/villages?district_id=1')->assertOk()->assertJsonPath('data', []);
    }
}
