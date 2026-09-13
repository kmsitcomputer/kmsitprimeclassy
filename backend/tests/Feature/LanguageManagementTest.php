<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bahasa/Translations management — the `languages` table itself, super_admin only, never a hard delete. */
class LanguageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
    }

    public function test_super_admin_can_create_and_update_a_language(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $create = $this->actingAs($superAdmin)->postJson('/api/v1/admin/languages', [
            'code' => 'fr', 'name' => 'French', 'sort_order' => 10,
        ]);
        $create->assertCreated();
        $this->assertFalse($create->json('data.is_default'));

        $id = $create->json('data.id');
        $update = $this->actingAs($superAdmin)->patchJson("/api/v1/admin/languages/{$id}", ['name' => 'Français']);
        $update->assertOk();
        $this->assertSame('Français', $update->json('data.name'));
    }

    public function test_default_language_cannot_be_deactivated_but_another_can_become_default_first(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $default = Language::query()->where('is_default', true)->firstOrFail();
        $other = Language::query()->where('is_default', false)->firstOrFail();

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/languages/{$default->id}/toggle-active")->assertStatus(422);

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/languages/{$other->id}/set-default")->assertOk();
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);

        // Now that it's no longer default, it can be deactivated.
        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/languages/{$default->id}/toggle-active")->assertOk();
        $this->assertFalse($default->fresh()->is_active);
    }

    public function test_only_super_admin_may_manage_languages(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($agen)->getJson('/api/v1/admin/languages')->assertStatus(403);
        $this->actingAs($agen)->postJson('/api/v1/admin/languages', ['code' => 'fr', 'name' => 'French'])->assertStatus(403);
    }
}
