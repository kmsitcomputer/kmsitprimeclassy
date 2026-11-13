<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Catalog\CatalogImportService;
use App\Services\Stock\StockOpnameService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * REM-01 under real concurrency (separate processes + MySQL connections):
 * the same import row submitted twice, and the same import opname approved twice, each yield exactly
 * one document / one stock adjustment / one catalogue publication.
 */
class CatalogImportConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $adminB = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'admin', 'adminB', 'gudang');
    }

    private function row(string $sku, string $price, string $stock): array
    {
        return ['type' => 'simple', 'sku' => $sku, 'product_slug' => '', 'product_name' => 'Race '.$sku, 'category_id' => '', 'status' => 'active', 'price' => $price, 'weight_grams' => '100', 'attributes' => '', 'stock_target' => $stock];
    }

    public function test_same_import_row_submitted_concurrently_creates_one_product_and_one_document(): void
    {
        $b = $this->branch();
        $sku = 'CIR-'.Str::upper(Str::random(8));
        $extra = ['rows' => [$this->row($sku, '1000', '7')], 'context' => ['agent_id' => null, 'stock_type' => 'transit', 'sub_location_id' => null], 'batch_key' => 'race-batch-0001'];

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'catalog-import-rows', 'actor_id' => $b['admin']->id, 'subject_id' => 0, 'extra' => $extra],
            ['op' => 'catalog-import-rows', 'actor_id' => $b['adminB']->id, 'subject_id' => 0, 'extra' => $extra],
        );

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame(1, Product::where('sku', $sku)->count(), json_encode($report));
        $this->assertSame(1, DB::table('catalog_skus')->where('sku', $sku)->count());
        $this->assertSame(1, StockOpnameItem::whereNotNull('import_row_key')->count(), json_encode($report));
        $this->assertSame('draft', Product::where('sku', $sku)->value('status')); // withheld until approval
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_same_import_opname_approved_concurrently_applies_stock_and_catalogue_exactly_once(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'CIA-'.Str::upper(Str::random(8)), 'name' => 'Old', 'slug' => 'cia-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $row = $this->row($p->sku, '4321', '6');
        $row['product_name'] = '';
        $this->actingAs($b['admin']);
        app(CatalogImportService::class)->apply($b['admin'], [$row], ['agent_id' => null, 'stock_type' => 'transit', 'sub_location_id' => null], 'approve-batch-0001');
        $item = StockOpnameItem::whereNotNull('import_row_key')->firstOrFail();
        $opname = $item->opname()->withoutGlobalScopes()->firstOrFail();
        app(StockOpnameService::class)->count($b['gudang'], $opname, [['item_id' => $item->id, 'counted_quantity' => 6]]);
        app(StockOpnameService::class)->submit($b['gudang'], $opname->fresh());

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'opname-approve', 'actor_id' => $b['admin']->id, 'subject_id' => $opname->id],
            ['op' => 'opname-approve', 'actor_id' => $b['adminB']->id, 'subject_id' => $opname->id],
        );

        $this->assertTrue($report['true_overlap']);
        $this->assertSame(6, (int) WarehouseStock::withoutGlobalScopes()->where('product_id', $p->id)->where('stock_type', 'transit')->value('quantity'), json_encode($report));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->count(), 'one stock adjustment');
        $this->assertSame(-4, (int) StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->sum('quantity'));
        $this->assertSame('4321.00', $p->fresh()->base_price, 'catalogue published once');
        $this->assertNotNull($item->fresh()->catalog_published_at);
    }
    public function test_approval_waiting_for_quantity_aba_rejects_current_token_on_another_connection(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'ABA-RACE', 'name' => 'Original', 'slug' => 'aba-race', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);
        $stock = WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $this->actingAs($b['admin']);
        app(CatalogImportService::class)->apply($b['admin'], [$this->row($p->sku, '4321', '6')], ['agent_id' => null, 'stock_type' => 'transit', 'sub_location_id' => null], 'aba-race-batch-0001');
        $item = StockOpnameItem::whereNotNull('import_row_key')->firstOrFail();
        $opname = $item->opname()->withoutGlobalScopes()->firstOrFail();
        app(StockOpnameService::class)->count($b['gudang'], $opname, [['item_id' => $item->id, 'counted_quantity' => 6]]);
        app(StockOpnameService::class)->submit($b['gudang'], $opname->fresh());
        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'physical-stock-aba', 'actor_id' => $b['admin']->id, 'subject_id' => $stock->id],
            ['op' => 'opname-approve', 'actor_id' => $b['adminB']->id, 'subject_id' => $opname->id, 'extra' => ['wait_for_aba' => true]],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame(409, $report['b']['business_status'], json_encode($report));
        $this->assertSame(10, $stock->fresh()->quantity);
        $this->assertSame('1000.00', $p->fresh()->base_price);
        $this->assertNull($item->fresh()->catalog_published_at);
        $this->assertSame('submitted', $opname->fresh()->status);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->count());
    }
}
