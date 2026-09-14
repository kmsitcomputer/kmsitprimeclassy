<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\GoogleSheetsException;
use App\Services\GoogleSheets\SheetsClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function agent(): User
    {
        $u = User::factory()->agen()->create();
        $u->update(['agent_id' => $u->id]);

        return $u;
    }

    private function config(User $agent): SheetsConfig
    {
        $d = SheetsDestination::create(['spreadsheet_id' => 'sheet-'.$agent->id, 'agent_id' => $agent->id]);

        return SheetsConfig::create(['destination_id' => $d->id, 'name' => 'Sales', 'tab' => 'Export', 'dataset' => 'sales', 'columns' => [['field' => 'name', 'label' => 'Nama']]]);
    }

    private function payload(SheetsConfig $c): array
    {
        return $c->only(['destination_id', 'name', 'tab', 'dataset', 'columns']);
    }

    public function test_access_matrix(): void
    {
        $agent = $this->agent();
        foreach (['superAdmin', 'admin', 'agen'] as $role) {
            $u = $role === 'agen' ? $agent : User::factory()->$role()->create(['agent_id' => $role === 'superAdmin' ? null : $agent->id]);
            $this->actingAs($u)->getJson('/api/v1/google-sheets')->assertOk();
        }
        foreach (['keuangan', 'korsal', 'sales', 'kurir', 'konsumen'] as $role) {
            $u = User::factory()->$role()->create(['agent_id' => $agent->id]);
            $this->actingAs($u)->getJson('/api/v1/google-sheets')->assertForbidden();
            $this->postJson('/api/v1/google-sheets/configs', [])->assertForbidden();
        }
    }

    public function test_agent_and_admin_cannot_read_edit_delete_or_sync_foreign_config(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $foreign = $this->config($b);
        foreach ([$a, User::factory()->admin()->create(['agent_id' => $a->id])] as $actor) {
            $this->actingAs($actor)->getJson('/api/v1/google-sheets?agent_id='.$b->id)->assertOk()->assertJsonCount(0, 'data.configs');
            $path = '/api/v1/google-sheets/configs/'.$foreign->id;
            $this->putJson($path, $this->payload($foreign))->assertForbidden();
            $this->deleteJson($path)->assertForbidden();
            $this->postJson($path.'/sync')->assertForbidden();
            $this->postJson('/api/v1/google-sheets/configs', array_merge($this->payload($foreign), ['tab' => 'Other']))->assertForbidden();
        }
    }

    public function test_agent_can_register_only_own_spreadsheet_and_full_url_is_normalized(): void
    {
        $a = $this->agent();
        $b = $this->agent();

        $response = $this->actingAs($a)->postJson('/api/v1/google-sheets/destinations', [
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/1ABC_xyz-123/edit#gid=0',
            'agent_id' => $b->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.spreadsheet_id', '1ABC_xyz-123');
        $this->assertDatabaseHas('sheets_destinations', [
            'spreadsheet_id' => '1ABC_xyz-123',
            'agent_id' => $a->id,
        ]);
    }

    public function test_connection_reads_real_spreadsheet_metadata_and_enforces_scope(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-a', 'agent_id' => $a->id]);
        $this->mock(SheetsClient::class, function ($mock) {
            $mock->shouldReceive('credentialInfo')->once()->andReturn([
                'resolved_path' => '/private/service-account.json', 'file_found' => true,
                'readable' => true, 'json_valid' => true, 'type' => 'service_account',
                'project_id' => 'project', 'client_email' => 'service@example.test',
                'private_key_present' => true,
            ]);
            $mock->shouldReceive('metadata')->once()->with('sheet-a')->andReturn([
                'title' => 'Prime Classy Agent A', 'sheets' => [],
            ]);
        });

        $this->actingAs($a)->postJson('/api/v1/google-sheets/connection', ['destination_id' => $destination->id])
            ->assertOk()
            ->assertJsonPath('data.spreadsheet_title', 'Prime Classy Agent A')
            ->assertJsonPath('data.service_account_email', 'service@example.test');
        $this->assertDatabaseHas('sheets_destinations', [
            'id' => $destination->id, 'connection_status' => 'connected',
            'spreadsheet_title' => 'Prime Classy Agent A',
        ]);
        $this->actingAs($b)->postJson('/api/v1/google-sheets/connection', ['destination_id' => $destination->id])
            ->assertForbidden();
    }

    public function test_missing_credentials_returns_a_safe_connection_error(): void
    {
        config(['google_sheets.enabled' => false]);
        $agent = $this->agent();
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-safe-error', 'agent_id' => $agent->id]);

        $response = $this->actingAs($agent)->postJson('/api/v1/google-sheets/connection', [
            'destination_id' => $destination->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Integrasi Google Sheets belum diaktifkan.')
            ->assertJsonPath('errors.code.0', 'INTEGRATION_DISABLED');
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $response->getContent());
    }

    public function test_dataset_whitelist_and_sensitive_fields_rejected(): void
    {
        $a = $this->agent();
        $c = $this->config($a);
        $this->actingAs($a);
        $this->putJson('/api/v1/google-sheets/configs/'.$c->id, array_merge($this->payload($c), ['dataset' => 'users']))->assertUnprocessable();
        $this->putJson('/api/v1/google-sheets/configs/'.$c->id, array_merge($this->payload($c), ['columns' => [['field' => 'password', 'label' => 'Secret']]]))->assertUnprocessable();
        $this->putJson('/api/v1/google-sheets/configs/'.$c->id, array_merge($this->payload($c), ['agent_id' => 999]))->assertUnprocessable();
    }

    public function test_own_sync_exports_only_own_network_and_creates_log(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $c = $this->config($a);
        User::factory()->sales()->create(['agent_id' => $a->id, 'name' => 'Own']);
        User::factory()->sales()->create(['agent_id' => $b->id, 'name' => 'Foreign']);
        $this->mock(SheetsClient::class, function ($mock) use ($a) {
            $mock->shouldReceive('replace')->once()->with('sheet-'.$a->id, 'Export', [['Nama'], ['Own']]);
            $mock->shouldReceive('credentialInfo')->andReturn(['client_email' => 'service@example.test']);
        });
        $this->actingAs($a)->postJson('/api/v1/google-sheets/configs/'.$c->id.'/sync')->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertDatabaseHas('sheets_sync_logs', ['agent_scope' => $a->id, 'rows_success' => 1, 'actor_id' => $a->id]);
        $this->actingAs($b)->getJson('/api/v1/google-sheets')->assertJsonCount(0, 'data.logs');
    }

    public function test_super_admin_can_sync_a_global_dataset_with_custom_header_and_column_order(): void
    {
        $agent = $this->agent();
        User::factory()->sales()->create(['agent_id' => $agent->id, 'name' => 'Global Sales', 'status' => 'active']);
        $destination = SheetsDestination::create(['spreadsheet_id' => 'global-sheet', 'agent_id' => null]);
        $config = SheetsConfig::create([
            'destination_id' => $destination->id,
            'name' => 'Global Sales',
            'tab' => 'Sales Report',
            'dataset' => 'sales',
            'columns' => [
                ['field' => 'status', 'label' => 'Status Akun'],
                ['field' => 'name', 'label' => 'Nama Sales'],
            ],
        ]);
        $this->mock(SheetsClient::class, fn ($mock) => $mock->shouldReceive('replace')->once()->with(
            'global-sheet',
            'Sales Report',
            [['Status Akun', 'Nama Sales'], ['active', 'Global Sales']],
        ));

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->postJson("/api/v1/google-sheets/configs/{$config->id}/sync")
            ->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertDatabaseHas('sheets_sync_logs', ['agent_scope' => null, 'rows_success' => 1]);
    }

    public function test_failure_does_not_rollback_business_data_or_expose_error(): void
    {
        $a = $this->agent();
        $c = $this->config($a);
        $p = Product::create(['name' => 'Kept', 'slug' => 'kept', 'has_variations' => false, 'sku' => 'KEPT']);
        $this->mock(SheetsClient::class, fn ($mock) => $mock->shouldReceive('replace')->once()->andThrow(new \RuntimeException('private_key SECRET')));
        $this->actingAs($a)->postJson('/api/v1/google-sheets/configs/'.$c->id.'/sync')->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_summary', 'Sync failed');
        $this->assertDatabaseHas('products', ['id' => $p->id, 'sku' => 'KEPT']);
    }

    public function test_all_dataset_queries_execute_with_explicit_columns(): void
    {
        $a = $this->agent();
        $registry = app(DatasetRegistry::class);
        foreach ($registry->definitions() as $key => $fields) {
            $registry->query($key, $a->id)->select($fields)->get();
            $registry->query($key, null)->select($fields)->get();
            $this->assertNotContains('password', $fields);
        }
    }

    public function test_client_clears_and_replaces_the_dedicated_tab_in_one_batch(): void
    {
        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/sheet-id*' => Http::sequence()
                ->push(['sheets' => [['properties' => ['sheetId' => 7, 'title' => 'Export']]]])
                ->push([]),
        ]);
        $client = new class extends SheetsClient
        {
            public function token(): string
            {
                return 'test-token';
            }
        };

        $client->replace('sheet-id', 'Export', [['SKU'], ['=SUM(1,1)']]);

        Http::assertSent(function ($request) {
            $requests = $request->data()['requests'] ?? [];

            return $request->method() === 'POST'
                && isset($requests[0]['repeatCell'], $requests[1]['updateCells'])
                && $requests[1]['updateCells']['rows'][1]['values'][0]['userEnteredValue']['stringValue'] === '=SUM(1,1)'
                && ! isset($requests[1]['updateCells']['rows'][1]['values'][0]['userEnteredValue']['formulaValue']);
        });
    }

    public function test_client_classifies_google_api_errors_without_exposing_response_body(): void
    {
        Http::fake([
            'sheets.googleapis.com/*' => Http::response([
                'error' => ['status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]],
            ], 403),
        ]);
        $client = new class extends SheetsClient
        {
            public function token(): string
            {
                return 'test-token';
            }
        };

        try {
            $client->metadata('sheet-id');
            $this->fail('Expected GoogleSheetsException');
        } catch (GoogleSheetsException $e) {
            $this->assertSame('API_DISABLED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
            $this->assertStringNotContainsString('details', $e->getMessage());
        }
    }

    public function test_admin_cannot_export_financial_summary_but_agent_can_see_it(): void
    {
        $agent = $this->agent();
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);

        $this->actingAs($admin)->getJson('/api/v1/google-sheets')
            ->assertOk()->assertJsonMissingPath('data.datasets.financial_summary');
        $this->actingAs($agent)->getJson('/api/v1/google-sheets')
            ->assertOk()->assertJsonPath('data.datasets.financial_summary.0', 'transaction_count');
    }
}
