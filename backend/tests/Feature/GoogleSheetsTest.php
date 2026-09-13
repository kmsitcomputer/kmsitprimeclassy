<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
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
        $this->mock(SheetsClient::class, fn ($mock) => $mock->shouldReceive('replace')->once()->with('sheet-'.$a->id, 'Export', [['Nama'], ['Own']]));
        $this->actingAs($a)->postJson('/api/v1/google-sheets/configs/'.$c->id.'/sync')->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertDatabaseHas('sheets_sync_logs', ['agent_scope' => $a->id, 'rows_success' => 1, 'actor_id' => $a->id]);
        $this->actingAs($b)->getJson('/api/v1/google-sheets')->assertJsonCount(0, 'data.logs');
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

        $client->replace('sheet-id', 'Export', [['SKU'], ['ABC']]);

        Http::assertSent(function ($request) {
            $requests = $request->data()['requests'] ?? [];

            return $request->method() === 'POST'
                && isset($requests[0]['repeatCell'], $requests[1]['updateCells'])
                && $requests[1]['updateCells']['rows'][1]['values'][0]['userEnteredValue']['stringValue'] === 'ABC';
        });
    }
}
