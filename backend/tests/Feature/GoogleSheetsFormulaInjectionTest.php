<?php

namespace Tests\Feature;

use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\Report\OrderTransactionReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSheetsFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_sheets_client_emits_formula_like_strings_as_explicit_literal_strings(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return $request->method() === 'GET'
                ? Http::response(['sheets' => [['properties' => ['sheetId' => 7, 'title' => 'Export']]]])
                : Http::response([]);
        });

        $client = new class extends SheetsClient
        {
            public function token(): string
            {
                return 'test-token';
            }
        };

        $values = [
            '=1+1',
            '+SUM(1,1)',
            '-1+1',
            '@SUM(1,1)',
            '=HYPERLINK("https://example.invalid","click")',
            ' =1+1',
            "\t=1+1",
            "'Prime",
            'Prime Classy',
            123,
            123.45,
            -50000,
            0,
            true,
            null,
            '00123',
        ];

        $client->replace('sheet-id', 'Export', [$values]);

        $payload = collect($requests)->first(fn ($request) => $request->method() === 'POST')->data();
        $cells = $payload['requests'][1]['updateCells']['rows'][0]['values'];

        foreach (array_keys($values) as $index) {
            $cell = $cells[$index]['userEnteredValue'];
            if (is_string($values[$index])) {
                $this->assertSame($values[$index], $cell['stringValue']);
                $this->assertArrayNotHasKey('formulaValue', $cell);
                $this->assertArrayNotHasKey('numberValue', $cell);
            } elseif (is_int($values[$index]) || is_float($values[$index])) {
                $this->assertSame($values[$index], $cell['numberValue']);
            } else {
                $this->assertSame(is_bool($values[$index]) ? ($values[$index] ? '1' : '') : '', $cell['stringValue']);
            }
        }
    }

    public function test_real_sync_path_captures_malicious_user_text_as_literal_string(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agent->id,
            'name' => '=HYPERLINK("https://example.invalid","click")',
        ]);
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-'.$agent->id, 'agent_id' => $agent->id]);
        $config = SheetsConfig::create([
            'destination_id' => $destination->id,
            'name' => 'Sales Export',
            'tab' => 'Export',
            'dataset' => 'sales',
            'columns' => [['field' => 'name', 'label' => 'Nama']],
        ]);

        $capturedRows = null;
        $this->mock(SheetsClient::class, function ($mock) use (&$capturedRows) {
            $mock->shouldReceive('replace')->once()->withArgs(function ($spreadsheet, $tab, $rows) use (&$capturedRows) {
                $capturedRows = $rows;

                return $spreadsheet !== '' && $tab === 'Export';
            });
        });

        $this->actingAs($agent)
            ->postJson('/api/v1/google-sheets/configs/'.$config->id.'/sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertSame([
            ['Nama'],
            ['=HYPERLINK("https://example.invalid","click")'],
        ], $capturedRows);
        $this->assertStringStartsWith('=', $capturedRows[1][0]);
    }

    public function test_dataset_registry_remains_whitelisted_and_transaction_contract_has_fourteen_columns(): void
    {
        $registry = app(DatasetRegistry::class);
        $this->assertNotContains('users', array_keys($registry->definitions()));
        $this->assertSame(14, count(OrderTransactionReportService::COLUMNS));
        $this->assertSame([
            'order_no', 'order_date', 'sku', 'product', 'unit_price', 'quantity',
            'item_status', 'subtotal', 'customer', 'delivery_date', 'courier',
            'order_status', 'sales', 'korsal',
        ], array_keys(OrderTransactionReportService::COLUMNS));
    }
}
