<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\Report\OrderStaffWorksheetService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Laporan C — 47-column staff worksheet (SimpleV2 TransactionService payload
 * geometry adapted to PrimeClassy) + the manual-column protection that keeps
 * staff DP/cicilan/pelunasan/keterangan entries safe across syncs.
 */
class GoogleSheetsStaffWorksheetTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $sheetWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Kue Jaya', 'address' => 'Jl. Merdeka No. 1',
            'latitude' => -6.2, 'longitude' => 106.816666,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'konsumen');
    }

    private function worksheetConfig(User $agent): SheetsConfig
    {
        $destination = SheetsDestination::create([
            'spreadsheet_id' => 'sheet-ws-'.$agent->id.'-'.Str::random(8), 'agent_id' => $agent->id,
        ]);

        return SheetsConfig::create([
            'destination_id' => $destination->id, 'name' => 'Worksheet', 'tab' => 'COOKIES_2026',
            'dataset' => DatasetRegistry::DATASET_STAFF_WORKSHEET,
            'columns' => app(DatasetRegistry::class)->defaults()[DatasetRegistry::DATASET_STAFF_WORKSHEET],
        ]);
    }

    private function makeProduct(User $agen): \App\Models\Product
    {
        $category = \App\Models\ProductCategory::create(['name' => 'Kue Kering', 'slug' => 'kue-kering-'.Str::random(6)]);

        return \App\Models\Product::create([
            'sku' => 'WS-'.Str::random(6), 'name' => 'Nastael', 'slug' => 'nastael-'.Str::random(6),
            'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 250,
            'status' => 'active', 'category_id' => $category->id,
        ]);
    }

    private function fakeSheetsClient(): void
    {
        $this->mock(SheetsClient::class, function ($mock) {
            $mock->shouldReceive('credentialInfo')->andReturn(['client_email' => 'service@example.test']);
            $mock->shouldReceive('replace')->andReturnUsing(function (string $spreadsheet, string $tab, array $rows, array $protectedIndexes = [], array $rowKeys = [], array $validationColumns = []): void {
                $this->sheetWrites[] = compact('spreadsheet', 'tab', 'rows', 'protectedIndexes', 'rowKeys', 'validationColumns');
            });
        });
    }

    private function sync(SheetsConfig $config, User $actor): void
    {
        $this->actingAs($actor)->postJson('/api/v1/google-sheets/configs/'.$config->id.'/sync')->assertOk();
    }

    public function test_worksheet_exposes_the_47_column_simplev2_contract_in_order(): void
    {
        $registry = app(DatasetRegistry::class);
        $fields = $registry->definitions()[DatasetRegistry::DATASET_STAFF_WORKSHEET];
        $labels = array_values(OrderStaffWorksheetService::COLUMNS);

        $this->assertCount(47, $fields);
        $this->assertSame(array_keys(OrderStaffWorksheetService::COLUMNS), $fields);
        // SV2 anchor positions (TransactionExport.php:117-161 headings).
        $this->assertSame('No', $labels[0]);
        $this->assertSame('No Faktur', $labels[1]);
        $this->assertSame('Sisa', $labels[20]);
        $this->assertSame('Status Pembayaran (Saat Transaksi)', $labels[22]);
        $this->assertSame('Total Bayar Pelunasan', $labels[32]);
        $this->assertSame('Tgl Kirim', $labels[33]);
        $this->assertSame('Hampers', $labels[42]);
        $this->assertSame(['Provinsi', 'Kota/Kabupaten', 'Kecamatan', 'Kelurahan/Desa'], array_slice($labels, 43, 4));

        // Owner mapping 2026-10-29 (WS2/WS3/WS10): only 3 columns lack a source.
        $this->assertCount(3, $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        $this->assertSame([], $registry->manualColumns('transactions'));
        $this->assertContains('update_money_position', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        $this->assertContains('settlement_note', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        // Reclassified SYSTEM/DERIVED:
        $this->assertNotContains('money_position', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));      // payment method
        $this->assertNotContains('update_payment_status', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET)); // latest status
        $this->assertNotContains('dp', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        $this->assertNotContains('driver_phone', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        $this->assertNotContains('hampers', $registry->manualColumns(DatasetRegistry::DATASET_STAFF_WORKSHEET));
        // Sheets data validation: the four canonical custody categories.
        $this->assertSame(
            ['update_money_position' => ['konsumen', 'keuangan', 'sales', 'korsal']],
            $registry->validationRules(DatasetRegistry::DATASET_STAFF_WORKSHEET),
        );
    }

    public function test_worksheet_writes_real_rows_with_manual_cells_empty_and_invoice_once(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-WS-0001', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 300000, 'discount_amount' => 20000, 'shipping_fee_amount' => 10000,
            'total_amount' => 290000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'Budi', 'recipient_phone_snapshot' => '0811',
            'address_snapshot' => 'Jl. Sudirman 1', 'notes' => 'rtc: blue box',
            'latitude_snapshot' => -6.91, 'longitude_snapshot' => 107.60,
        ]);
        foreach ([['K-1', 'Kue Kering', 100000, 2], ['C-2', 'Choco Cake', 150000, 1]] as [$sku, $name, $price, $qty]) {
            $order->items()->create([
                'product_id' => $this->makeProduct($agen)->id, 'product_name_snapshot' => $name, 'sku_snapshot' => $sku,
                'unit_price_snapshot' => $price, 'subtotal_snapshot' => $price,
                'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima',
            ]);
        }

        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($agen), $agen);
        $write = $this->sheetWrites[0];
        [$header, $rows] = [$write['rows'][0], array_slice($write['rows'], 1)];

        $this->assertCount(47, $header);
        $this->assertCount(2, $rows);
        $idx = array_flip($header);
        // System columns carry real DB values.
        $this->assertSame('PC-WS-0001', $rows[0][$idx['No Faktur']]);
        $this->assertSame('Budi', $rows[0][$idx['Konsumen']]);
        $this->assertSame('rtc: blue box', $rows[0][$idx['Catatan']]);
        // Invoice-level money only on the FIRST item row (anti double-count).
        $this->assertEquals(290000.0, $rows[0][$idx['Total Pembayaran']]);
        $this->assertNull($rows[1][$idx['Total Pembayaran']]);
        // Unpaid collectible = total (KF-1 fix), via the canonical derivation.
        $this->assertEquals(290000.0, $rows[0][$idx['Sisa']]);
        // DP/Cicilan/Pelunasan derived from canonical payment markers — this
        // order has NO verified payments, so they are blank, never fabricated 0.
        $this->assertNull($rows[0][$idx['DP']]);
        $this->assertNull($rows[0][$idx['Cicilan 1']]);
        $this->assertNull($rows[0][$idx['Pelunasan']]);
        // Hampers derived from the product category (test product is Kue Kering).
        $this->assertSame('tidak', $rows[0][$idx['Hampers']]);
        // WS3: 'Update Status Pembayaran' is the SYSTEM latest canonical status.
        $this->assertSame('unpaid', $rows[0][$idx['Update Status Pembayaran']]);
        // WS2: initial money position is DERIVED from the payment method —
        // this fixture has no payment method, so it is unresolved (null), not invented.
        $this->assertNull($rows[0][$idx['Posisi Uang (Saat Transaksi)']]);
        // The five true-manual columns are written empty by the system side.
        foreach (OrderStaffWorksheetService::MANUAL_FIELDS as $field) {
            $this->assertSame('', $rows[0][$idx[OrderStaffWorksheetService::COLUMNS[$field]]], "manual $field");
        }
    }

    public function test_sync_passes_manual_column_positions_as_protected(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($agen), $agen);
        $write = $this->sheetWrites[0];

        // Post-Owner mapping: only update_money_position (25), settlement_note
        // (31) and settlement_total (32) are protected.
        $this->assertSame([25, 31, 32], $write['protectedIndexes']);
        // Real Sheets dropdown for the manual custody column (ONE_OF_LIST).
        $this->assertSame([25 => ['konsumen', 'keuangan', 'sales', 'korsal']], $write['validationColumns']);

        // A non-worksheet dataset never protects columns (backward compatible).
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-plain-'.$agen->id, 'agent_id' => $agen->id]);
        $config = SheetsConfig::create([
            'destination_id' => $destination->id, 'name' => 'Plain', 'tab' => 'Export',
            'dataset' => 'sales', 'columns' => [['field' => 'name', 'label' => 'Nama']],
        ]);
        $this->sync($config, $agen);
        $this->assertSame([], $this->sheetWrites[1]['protectedIndexes']);
    }

    public function test_client_merge_never_clears_or_rewrites_protected_data_cells(): void
    {
        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/ws-id*' => Http::sequence()
                ->push(['sheets' => [['properties' => ['sheetId' => 9, 'title' => 'COOKIES_2026']]]])   // metadata
                ->push(['values' => [                                                                    // values.get
                    ['OLD-HDR', 'B', 'DP', 'C', 'D'],
                    ['1', 'oldB', 'STAFF-50000', 'oldC', 'oldD'],   // existing transaction: manual cell survives
                    ['99', 'kept', 'STAFF-ORPHAN', 'kept', 'kept'], // id not in new dataset: row kept verbatim
                ]])
                ->push([]),                                                                               // batchUpdate
        ]);
        $client = new class extends SheetsClient
        {
            public function token(): string
            {
                return 'test-token';
            }
        };

        // 5 columns; position 2 (DP) protected. New dataset ids: 1 (update), 2 (append).
        $rows = [['No', 'B', 'DP', 'C', 'D'], ['1', 'newB', '', 'newC', 'newD'], ['2', 'fresh', '', 'x', 'y']];
        $client->replace('ws-id', 'COOKIES_2026', $rows, [2], ['1', '2']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $requests = collect($request->data()['requests'] ?? []);

            // 1) NOTHING is ever cleared in merge mode (repeatCell forbidden).
            if ($requests->contains(fn ($r) => isset($r['repeatCell']))) {
                return false;
            }
            // 2) No updateCells request may span the protected column for DATA rows
            //    (only the full-width header block may touch column 2, row 0).
            foreach ($requests as $r) {
                if (! isset($r['updateCells'])) {
                    continue;
                }
                $rng = $r['updateCells']['range'];
                $spans = ($rng['startColumnIndex'] ?? 0) <= 2 && ($rng['endColumnIndex'] ?? 0) > 2;
                $dataRows = ($rng['startRowIndex'] ?? 0) > 0;
                if ($spans && $dataRows) {
                    return false;
                }
            }
            // 3) Existing row for id=1 updated in place with new system values;
            //    orphan id=99 kept verbatim; id=2 appended after them.
            $body = json_encode($request->data());

            return str_contains($body, 'newB') && str_contains($body, 'STAFF') === false
                && str_contains($body, 'kept') && str_contains($body, 'fresh');
        });
    }

    public function test_merge_requires_row_identity_for_protected_datasets(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-noid-'.$agen->id, 'agent_id' => $agen->id]);
        $config = SheetsConfig::create([
            'destination_id' => $destination->id, 'name' => 'NoID', 'tab' => 'COOKIES_2026',
            'dataset' => DatasetRegistry::DATASET_STAFF_WORKSHEET,
            // 47 default columns MINUS the 'no' identity column.
            'columns' => array_values(array_filter(
                app(DatasetRegistry::class)->defaults()[DatasetRegistry::DATASET_STAFF_WORKSHEET],
                fn ($c) => $c['field'] !== 'no',
            )),
        ]);
        $this->fakeSheetsClient();
        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs/'.$config->id.'/sync')
            ->assertOk()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_summary', 'Invalid mapping');
    }

    public function test_worksheet_cancelled_order_sisa_is_zero_and_other_values_remain_real(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-WS-0002', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'dibatalkan', 'payment_status' => 'unpaid',
            'subtotal_amount' => 50000, 'total_amount' => 50000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'Budi', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'x',
        ]);
        $order->items()->create([
            'product_id' => $this->makeProduct($agen)->id, 'product_name_snapshot' => 'Kue A', 'sku_snapshot' => 'A',
            'unit_price_snapshot' => 50000, 'subtotal_snapshot' => 50000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'dibatalkan',
        ]);

        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($agen), $agen);
        $write = $this->sheetWrites[0];
        [$header, $row] = [$write['rows'][0], $write['rows'][1]];
        $idx = array_flip($header);

        $this->assertEquals(0, (float) $row[$idx['Sisa']]);                      // canonical: cancelled contributes 0
        $this->assertEquals(50000.0, $row[$idx['Total']]);                       // line value NOT blanked (PC convention)
    }

    public function test_derived_money_columns_and_hampers_come_from_verified_rows(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-WS-0003', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'diproses', 'payment_status' => 'partially_paid',
            'subtotal_amount' => 300000, 'total_amount' => 300000, 'paid_amount' => 100000, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'Budi', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Jl. X',
        ]);
        $normal = $this->makeProduct($agen);
        $hamperCat = \App\Models\ProductCategory::create(['name' => 'Hampers', 'slug' => 'hampers-'.Str::random(6)]);
        $hamper = \App\Models\Product::create([
            'sku' => 'HMPS-'.Str::random(6), 'name' => 'Hampers Lebaran', 'slug' => 'hampers-'.Str::random(6),
            'has_variations' => false, 'base_price' => 80000, 'weight_grams' => 500, 'status' => 'active',
            'category_id' => $hamperCat->id,
        ]);
        foreach ([[false, $normal, 'K-1', 'Kue Kering', 100000], [true, $hamper, 'H-1', 'Hampers Lebaran', 80000]] as [$isHamp, $prod, $sku, $name, $price]) {
            $order->items()->create([
                'product_id' => $prod->id, 'product_name_snapshot' => $name, 'sku_snapshot' => $sku,
                'unit_price_snapshot' => $price, 'subtotal_snapshot' => $price,
                'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
            ]);
        }
        $pm = DB::table('payment_methods')->value('id');
        $mk = function (string $note, int $amount, string $status, string $paidAt) use ($order, $pm) {
            DB::table('payment_transactions')->insert([
                'order_id' => $order->id, 'payment_method_id' => $pm, 'type' => 'payment',
                'amount' => $amount, 'status' => $status, 'gateway_reference' => 'MK-'.Str::random(8),
                'raw_payload' => json_encode(['note' => $note]), 'paid_at' => $paidAt,
                'created_at' => $paidAt, 'updated_at' => $paidAt,
            ]);
        };
        $mk('down_payment', 100000, 'paid', '2026-10-15 10:00:00');
        $mk('', 50000, 'paid', '2026-10-18 10:00:00');              // cicilan
        $mk('dp_settlement', 100000, 'paid', '2026-10-19 10:00:00'); // pelunasan
        $mk('down_payment', 777000, 'pending', '2026-10-20 10:00:00'); // UNVERIFIED — must never appear

        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($agen), $agen);
        $write = $this->sheetWrites[0];
        [$header, $rows] = [$write['rows'][0], array_slice($write['rows'], 1)];
        $idx = array_flip($header);
        $firstRow = $rows[0]; // 'no' ASC: the first inserted item

        $this->assertEquals(100000.0, $firstRow[$idx['DP']]);
        $this->assertEquals(50000.0, $firstRow[$idx['Cicilan 1']]);
        $this->assertSame('18/10/2026', $firstRow[$idx['Tgl Cicilan 1']]);
        $this->assertEquals(100000.0, $firstRow[$idx['Pelunasan']]);
        $this->assertSame('19/10/2026', $firstRow[$idx['Tgl Pelunasan']]);
        $this->assertStringNotContainsString('777000', json_encode($rows)); // pending money never counted
        // Invoice-level once only: second item row has blank DP.
        $this->assertNull($rows[1][$idx['DP']]);
        // Hampers per ITEM category.
        $hamp = array_column($rows, $idx['Hampers']);
        $this->assertContains('ya', $hamp);
        $this->assertContains('tidak', $hamp);
    }

    public function test_initial_money_position_is_derived_from_the_payment_method(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $ids = [
            'cod' => DB::table('payment_methods')->where('code', 'cod')->value('id'),
            'bank_transfer' => DB::table('payment_methods')->where('code', 'bank_transfer')->value('id'),
            'down_payment' => DB::table('payment_methods')->where('code', 'down_payment')->value('id'),
        ];
        DB::table('payment_methods')->insert([
            'code' => 'other_gateway', 'name' => 'Lainnya', 'type' => 'manual', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['other'] = DB::table('payment_methods')->where('code', 'other_gateway')->value('id');

        foreach ($ids as $code => $pmId) {
            $o = Order::withoutGlobalScopes()->create([
                'order_no' => 'PC-MP-'.$code, 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
                'status' => 'diproses', 'payment_status' => 'unpaid', 'payment_method_id' => $pmId,
                'subtotal_amount' => 1000, 'total_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 0,
                'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
            ]);
            $o->items()->create([
                'product_id' => $this->makeProduct($agen)->id, 'product_name_snapshot' => 'Kue', 'sku_snapshot' => 'K',
                'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000,
                'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
            ]);
        }

        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($agen), $agen);
        $write = $this->sheetWrites[0];
        [$header, $rows] = [$write['rows'][0], array_slice($write['rows'], 1)];
        $idx = array_flip($header);
        $byInvoice = [];
        foreach ($rows as $r) {
            $byInvoice[$r[$idx['No Faktur']]] = $r[$idx['Posisi Uang (Saat Transaksi)']];
        }

        $this->assertSame('konsumen', $byInvoice['PC-MP-cod']);
        $this->assertSame('keuangan', $byInvoice['PC-MP-bank_transfer']);
        $this->assertSame('keuangan', $byInvoice['PC-MP-down_payment']);
        $this->assertNull($byInvoice['PC-MP-other']);          // unsupported -> explicit unresolved, never invented
        // Current custody stays the manual column and is untouched by derivation.
        $this->assertSame('', $rows[0][$idx['Update Posisi Uang']]);
    }

    public function test_worksheet_is_agent_isolated_and_period_filterable(): void
    {
        ['agen' => $a1] = $this->makeAgentBranch();
        ['agen' => $a2, 'konsumen' => $k2] = $this->makeAgentBranch();
        foreach ([[$a1, 'PC-WS-A1'], [$a2, 'PC-WS-A2']] as [$agent, $no]) {
            Order::withoutGlobalScopes()->create([
                'order_no' => $no, 'agent_id' => $agent->id,
                'status' => 'diproses', 'payment_status' => 'unpaid',
                'subtotal_amount' => 10000, 'total_amount' => 10000, 'paid_amount' => 0, 'remaining_amount' => 0,
                'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
            ])->items()->create([
                'product_id' => $this->makeProduct($agent)->id, 'product_name_snapshot' => 'Kue', 'sku_snapshot' => 'K',
                'unit_price_snapshot' => 10000, 'subtotal_snapshot' => 10000,
                'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
            ]);
        }

        $this->fakeSheetsClient();
        $this->sync($this->worksheetConfig($a1), $a1);
        $rows = array_slice($this->sheetWrites[0]['rows'], 1);
        $this->assertSame(['PC-WS-A1'], array_map(fn ($r) => $r[1], $rows));   // own branch only
        $this->assertNotEmpty($rows[0][0]);                                     // No = item id

        // Period filter: a daily window excludes orders outside today's WIB day.
        $ownOrder = Order::withoutGlobalScopes()->where('order_no', 'PC-WS-A1')->first();
        $ownOrder->created_at = Carbon::parse('2026-10-18 09:00:00', 'UTC');
        $ownOrder->save();
        $config = $this->worksheetConfig($a1);
        $config->update(['filters' => ['period' => 'daily']]);
        $this->sync($config, $a1);
        $this->assertSame([], array_slice($this->sheetWrites[1]['rows'], 1));
    }

    public function test_category_group_splits_mixed_orders_item_level(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $cakeCat = \App\Models\ProductCategory::create(['name' => 'Cake', 'slug' => 'cake-'.Str::random(6)]);
        $cookieCat = \App\Models\ProductCategory::create(['name' => 'Cookies', 'slug' => 'cookies-'.Str::random(6)]);
        $hamperCat = \App\Models\ProductCategory::create(['name' => 'Hampers', 'slug' => 'hampers-'.Str::random(6)]);
        $mk = fn ($cat, $name) => \App\Models\Product::create([
            'sku' => 'SPL-'.Str::random(6), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6),
            'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100,
            'status' => 'active', 'category_id' => $cat->id,
        ]);
        $cake = $mk($cakeCat, 'Cake Coklat');
        $cookie = $mk($cookieCat, 'Kue Kering');
        $hamper = $mk($hamperCat, 'Hampers Kecil');

        // ONE mixed order: cake + cookies (must be split per ITEM, never whole-order).
        $o1 = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-SPL-1', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 2000, 'total_amount' => 2000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
        ]);
        foreach ([[$cake, 'CAKE-1', 'Cake Coklat'], [$cookie, 'COOK-1', 'Kue Kering']] as [$p, $sku, $name]) {
            $o1->items()->create([
                'product_id' => $p->id, 'product_name_snapshot' => $name, 'sku_snapshot' => $sku,
                'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000,
                'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
            ]);
        }
        // A hampers-only order: SV2 routes non-cake to COOKIES_2026 by default.
        $o2 = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-SPL-2', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 1000, 'total_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
        ]);
        $o2->items()->create([
            'product_id' => $hamper->id, 'product_name_snapshot' => 'Hampers Kecil', 'sku_snapshot' => 'HMP-1',
            'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
        ]);

        $this->fakeSheetsClient();
        $cfg = $this->worksheetConfig($agen);
        $cfg->update(['filters' => ['category_group' => 'cake']]);
        $this->sync($cfg, $agen);
        $cakeRows = array_slice($this->sheetWrites[0]['rows'], 1);
        $this->assertCount(1, $cakeRows);
        $this->assertSame('CAKE-1', $cakeRows[0][10]);              // SKU of the cake line only

        $cfg2 = $this->worksheetConfig($agen);
        $cfg2->update(['filters' => ['category_group' => 'cookies']]);
        $this->sync($cfg2, $agen);
        $cookieRows = array_slice($this->sheetWrites[1]['rows'], 1);
        $this->assertCount(2, $cookieRows);                          // cookies + hampers (SV2 default)
        $skus = array_column($cookieRows, 10);
        $this->assertEqualsCanonicalizing(['COOK-1', 'HMP-1'], $skus);

        $cfg3 = $this->worksheetConfig($agen);
        $this->sync($cfg3, $agen);
        $this->assertCount(3, array_slice($this->sheetWrites[2]['rows'], 1)); // no filter => everything
    }

    public function test_repeat_merge_sync_produces_identical_requests(): void
    {
        $metadata = ['sheets' => [['properties' => ['sheetId' => 9, 'title' => 'COOKIES_2026']]]];
        $values = ['values' => [
            ['No', 'B', 'CUSTODY'],
            ['1', 'oldB', 'sales'],
        ]];
        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/ws-id*' => Http::sequence()
                ->push($metadata)->push($values)->push([])
                ->push($metadata)->push($values)->push([]),
        ]);
        $client = new class extends SheetsClient
        {
            public function token(): string
            {
                return 'test-token';
            }
        };
        $rows = [['No', 'B', 'CUSTODY'], ['1', 'newB', ''], ['2', 'fresh', '']];
        $client->replace('ws-id', 'COOKIES_2026', $rows, [2], ['1', '2'], [2 => array_values(['konsumen', 'keuangan', 'sales', 'korsal'])]);
        $client->replace('ws-id', 'COOKIES_2026', $rows, [2], ['1', '2'], [2 => array_values(['konsumen', 'keuangan', 'sales', 'korsal'])]);

        $bodies = [];
        Http::recorded(function ($request) use (&$bodies) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'batchUpdate')) {
                $bodies[] = $request->body();
            }

            return false; // only collect; no assertion predicate needed
        });
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);   // idempotent: identical request set
    }

    public function test_super_admin_and_admin_can_create_worksheet_config_agent_sees_own_only(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-rbac-'.$agen->id, 'agent_id' => $agen->id]);

        foreach ([$super, $agen, $admin] as $actor) {
            $this->actingAs($actor)->postJson('/api/v1/google-sheets/configs', [
                'destination_id' => $destination->id, 'name' => 'WS-'.$actor->id, 'tab' => 'CAKE_'.$actor->id,
                'dataset' => DatasetRegistry::DATASET_STAFF_WORKSHEET,
                'columns' => app(DatasetRegistry::class)->defaults()[DatasetRegistry::DATASET_STAFF_WORKSHEET],
            ])->assertCreated();
        }
        $finance = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $this->actingAs($finance)->getJson('/api/v1/google-sheets')->assertForbidden();
    }
}
