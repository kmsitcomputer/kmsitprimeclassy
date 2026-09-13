<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Audit Log viewer — read-only browse of ActivityLogger entries, super_admin only. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_super_admin_can_browse_audit_logs_and_filter_by_event(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        // Creating an agen already logs a real 'user.created' entry.
        $this->actingAs($superAdmin)->postJson('/api/v1/users', [
            'role' => 'agen', 'name' => 'Agen Baru', 'email' => 'agen-baru-'.uniqid().'@example.com',
            'phone' => '0811', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated();

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/admin/audit-logs?event=user.created');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
        $this->assertSame('user.created', $response->json('data.0.event'));
        $this->assertSame($superAdmin->id, $response->json('data.0.causer.id'));
    }

    public function test_only_super_admin_may_browse_audit_logs(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($agen)->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    }
}
