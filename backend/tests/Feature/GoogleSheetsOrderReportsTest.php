<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\SheetsSyncLog;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\SheetsClient;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * WP-04 (Owner 2026-10-30): Laporan A (Transaksi Awal) dan Laporan B (Posisi Pembayaran
 * Terkini) DIM retiring — nonaktif dari surface Google Sheets, datanya DIPERTAHANKAN.
 *
 * Contract that must survive the retirement:
 *  1. The two datasets disappear from the registry, the configs API and the catalog UI
 *     payload — creating/renaming a config onto them is refused (422), not hidden.
 *  2. An EXISTING config still pointing at a retired dataset fails its sync EXPLICITLY
 *     (sync log `failed`, "Dataset tidak diizinkan") instead of writing a silent stub.
 *  3. History is retained: the checkout snapshot writer still fills
 *     `order_initial_report_items`, Laporan C (`transaction_worksheet`) stays live.
 *
 * (The former per-report GoogleSheetsOrderReportsTest contract was retired together with
 * the two datasets; classes `OrderInitialReportService` / `OrderPaymentPositionReportService`
 * remain on disk untouched for reactivation/historical export.)
 */
class GoogleSheetsOrderReportsTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Kue Jaya', 'address' => 'Jl. Merdeka No. 1',
            'latitude' => -6.200000, 'longitude' => 106.816666,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'konsumen');
    }

    private function checkout(User $konsumen, array $items): array
    {
        // Codemod left $agen undefined here: resolve the buyer's branch agen
        // (staff-only COD per Improvement 04) instead of the missing parameter.
        $agen = User::query()->findOrFail($konsumen->agent_id);
        $response = $this->actingAs($agen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
                'payment_method_code' => 'cod',
                'items' => $items,
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(),
                'latitude' => -6.914744, 'longitude' => 107.609810,
            ]);
        $response->assertCreated();

        return $response->json('data');
    }

    public function test_retired_datasets_are_gone_from_the_registry_config_api_and_catalog(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $registry = app(DatasetRegistry::class);

        foreach (['order_initial_transactions', 'order_payment_positions'] as $retired) {
            $this->assertArrayNotHasKey($retired, $registry->definitions());
            $this->assertArrayNotHasKey($retired, $registry->defaults());
            $this->assertArrayNotHasKey($retired, $registry->supportedFilters());
        }
        $catalogKeys = array_keys($registry->catalog());
        $this->assertNotContains('order_initial_transactions', $catalogKeys);
        $this->assertNotContains('order_payment_positions', $catalogKeys);
        // Laporan C stays live.
        $this->assertContains(DatasetRegistry::DATASET_STAFF_WORKSHEET, $catalogKeys);

        $sheets = $this->actingAs($agen)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
        $this->assertNotContains('order_initial_transactions', array_keys($sheets['datasets']));
        $this->assertNotContains('order_payment_positions', array_keys($sheets['datasets']));
        $this->assertContains(DatasetRegistry::DATASET_STAFF_WORKSHEET, array_keys($sheets['datasets']));

        // Creating a config onto a retired dataset is refused at the API (not just hidden).
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-retired-'.uniqid(), 'agent_id' => $agen->id]);
        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', [
            'destination_id' => $destination->id,
            'name' => 'Laporan A',
            'tab' => 'LaporanA',
            'dataset' => 'order_initial_transactions',
            'columns' => [['field' => 'invoice_no', 'label' => 'No Faktur']],
        ])->assertUnprocessable()->assertJsonValidationErrors('dataset');
    }

    public function test_existing_config_on_a_retired_dataset_fails_its_sync_explicitly(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-legacy-'.uniqid(), 'agent_id' => $agen->id]);
        $config = SheetsConfig::create([
            'destination_id' => $destination->id,
            'name' => 'Legacy Laporan A',
            'tab' => 'LaporanA',
            'dataset' => 'order_initial_transactions',
            'columns' => [['field' => 'invoice_no', 'label' => 'No Faktur']],
        ]);

        $this->mock(SheetsClient::class, function ($mock) {
            $mock->shouldReceive('credentialInfo')->andReturn(['client_email' => 'service@example.test']);
            $mock->shouldReceive('replace')->never(); // Never a silent stub write.
        });

        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs/'.$config->id.'/sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $log = SheetsSyncLog::query()->where('config_id', $config->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
    }

    public function test_history_is_retained_snapshot_writer_and_laporan_c_intact(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Red Velvet Cake', 'slug' => 'red-velvet-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $order = $this->checkout($konsumen, [['product_id' => $product->id, 'quantity' => 2]]);

        // Retention: the transaction-time snapshot rows keep existing even though the dataset is gone.
        $this->assertDatabaseHas('order_initial_report_items', ['order_id' => $order['id']]);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'order_no' => $order['order_no']]);
    }
}
