<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\Report\OrderStaffWorksheetService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * What the Google Sheets configuration screen is told about the datasets:
 * the 47-column Laporan C must be selectable per role with its column
 * classification, Admin must never receive (or configure) a dataset carrying
 * Agent commission, and the Owner's four money-position values are exposed.
 */
class GoogleSheetsCatalogTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private const WORKSHEET = DatasetRegistry::DATASET_STAFF_WORKSHEET;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Kue', 'address' => 'Jl. Merdeka 1',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $destination = SheetsDestination::create(['spreadsheet_id' => 'sheet-cat-'.$agen->id, 'agent_id' => $agen->id]);

        return compact('agen', 'admin', 'destination');
    }

    public function test_every_role_that_can_open_sheets_receives_the_47_column_catalog_with_classification(): void
    {
        ['agen' => $agen, 'admin' => $admin] = $this->branch();
        $super = User::factory()->superAdmin()->create();

        foreach ([$super, $agen, $admin] as $actor) {
            $data = $this->actingAs($actor)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
            $entry = $data['dataset_catalog'][self::WORKSHEET];

            $this->assertSame(47, $entry['column_count']);
            $this->assertSame(47, $entry['default_column_count']);
            $this->assertCount(47, $entry['columns']);
            $this->assertSame(array_keys(OrderStaffWorksheetService::COLUMNS), array_column($entry['columns'], 'field'));
            $this->assertSame(
                OrderStaffWorksheetService::MANUAL_FIELDS,
                array_values(array_column(array_filter($entry['columns'], fn ($c) => $c['classification'] === 'MANUAL'), 'field')),
            );
            $this->assertSame('DERIVED', collect($entry['columns'])->firstWhere('field', 'dp')['classification']);
            $this->assertSame('SYSTEM', collect($entry['columns'])->firstWhere('field', 'invoice_no')['classification']);
            $this->assertSame(['COOKIES_2026' => 'cookies', 'CAKE_2026' => 'cake'], $entry['suggested_tabs']);
            $this->assertContains('category_group', $entry['filters']);
            $this->assertArrayHasKey(self::WORKSHEET, $data['datasets']);
        }
    }

    public function test_money_position_uses_exactly_the_four_owner_categories(): void
    {
        ['agen' => $agen] = $this->branch();

        $data = $this->actingAs($agen)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
        $this->assertSame(['konsumen', 'keuangan', 'sales', 'korsal'], $data['money_positions']);

        $columns = collect($data['dataset_catalog'][self::WORKSHEET]['columns']);
        foreach (['money_position', 'update_money_position'] as $field) {
            $this->assertSame(['konsumen', 'keuangan', 'sales', 'korsal'], $columns->firstWhere('field', $field)['allowed_values']);
        }
        $this->assertNull($columns->firstWhere('field', 'dp')['allowed_values'] ?? null);
    }

    public function test_admin_never_receives_or_configures_the_agent_commission_dataset(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'destination' => $destination] = $this->branch();

        $adminData = $this->actingAs($admin)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
        $this->assertArrayNotHasKey('financial_summary', $adminData['datasets']);
        $this->assertArrayNotHasKey('financial_summary', $adminData['dataset_catalog']);
        $this->assertArrayNotHasKey('financial_summary', $adminData['dataset_defaults']);
        $this->assertStringNotContainsString('agent_fee', json_encode($adminData['dataset_catalog']));

        $agenData = $this->actingAs($agen)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
        $this->assertArrayHasKey('financial_summary', $agenData['dataset_catalog']);

        $payload = [
            'destination_id' => $destination->id, 'name' => 'Fin', 'tab' => 'FIN',
            'dataset' => 'financial_summary', 'columns' => [['field' => 'agent_fee', 'label' => 'Fee Agen']],
        ];
        $this->actingAs($admin)->postJson('/api/v1/google-sheets/configs', $payload)->assertForbidden();
        $this->assertSame(0, SheetsConfig::where('dataset', 'financial_summary')->count());

        // Moving an allowed config onto the commission dataset is refused as well.
        $config = SheetsConfig::create([
            'destination_id' => $destination->id, 'name' => 'Ok', 'tab' => 'OK', 'dataset' => 'payment_status',
            'columns' => [['field' => 'order_no', 'label' => 'No']],
        ]);
        $this->actingAs($admin)->putJson('/api/v1/google-sheets/configs/'.$config->id, ['tab' => 'OK'] + $payload)->assertForbidden();
        $this->assertSame('payment_status', $config->fresh()->dataset);

        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', $payload)->assertCreated();
    }

    public function test_category_group_filter_splits_cake_from_cookies_and_is_worksheet_only(): void
    {
        ['agen' => $agen, 'destination' => $destination] = $this->branch();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-CAT-1', 'agent_id' => $agen->id, 'konsumen_id' => $konsumen->id,
            'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 200000,
            'discount_amount' => 0, 'shipping_fee_amount' => 0, 'total_amount' => 200000,
            'paid_amount' => 0, 'remaining_amount' => 0, 'recipient_name_snapshot' => 'Budi',
            'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Jl. 1',
        ]);
        foreach ([['Cookies', 'S-1'], ['Cake', 'C-1']] as [$category, $sku]) {
            $cat = ProductCategory::create(['name' => $category, 'slug' => Str::slug($category).'-'.Str::random(5)]);
            $product = Product::create([
                'sku' => $sku.Str::random(4), 'name' => $category.' item', 'slug' => Str::slug($category).'-p-'.Str::random(5),
                'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 250,
                'status' => 'active', 'category_id' => $cat->id,
            ]);
            $order->items()->create([
                'product_id' => $product->id, 'product_name_snapshot' => $category.' item', 'sku_snapshot' => $sku,
                'unit_price_snapshot' => 100000, 'subtotal_snapshot' => 100000,
                'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
            ]);
        }

        $registry = app(DatasetRegistry::class);
        $skus = fn (array $filters) => $registry->query(self::WORKSHEET, $agen->id, $filters)->pluck('sku')->all();

        $this->assertEqualsCanonicalizing(['S-1', 'C-1'], $skus([]));
        $this->assertSame(['C-1'], $skus(['category_group' => 'cake']));
        $this->assertSame(['S-1'], $skus(['category_group' => 'cookies']));

        $columns = [['field' => 'no', 'label' => 'No'], ['field' => 'sku', 'label' => 'Kode Kue']];
        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', [
            'destination_id' => $destination->id, 'name' => 'Cake', 'tab' => 'CAKE_2026', 'dataset' => self::WORKSHEET,
            'columns' => $columns, 'filters' => ['category_group' => 'cake'],
        ])->assertCreated();
        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', [
            'destination_id' => $destination->id, 'name' => 'Bad', 'tab' => 'X1', 'dataset' => self::WORKSHEET,
            'columns' => $columns, 'filters' => ['category_group' => 'bogus'],
        ])->assertUnprocessable();
        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', [
            'destination_id' => $destination->id, 'name' => 'Other', 'tab' => 'X2', 'dataset' => 'products',
            'columns' => [['field' => 'sku', 'label' => 'SKU']], 'filters' => ['category_group' => 'cake'],
        ])->assertUnprocessable();
    }

    public function test_agent_cannot_use_another_branch_destination(): void
    {
        ['agen' => $agen] = $this->branch();
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $foreign = SheetsDestination::create(['spreadsheet_id' => 'sheet-foreign-'.$other->id, 'agent_id' => $other->id]);

        $data = $this->actingAs($agen)->getJson('/api/v1/google-sheets')->assertOk()->json('data');
        $this->assertNotContains($foreign->id, array_column($data['destinations'], 'id'));

        $this->actingAs($agen)->postJson('/api/v1/google-sheets/configs', [
            'destination_id' => $foreign->id, 'name' => 'X', 'tab' => 'X', 'dataset' => self::WORKSHEET,
            'columns' => [['field' => 'no', 'label' => 'No']],
        ])->assertForbidden();
    }
}
