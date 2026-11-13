<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockMovement;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** REM-01 / ODR-03 B / D-07 A regressions. */
class CatalogImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const H = 'type,sku,product_slug,product_name,category_id,status,price,weight_grams,attributes,stock_target';

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
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'admin', 'gudang');
    }

    private function csv(string ...$lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('katalog.csv', implode("\n", [self::H, ...$lines])."\n");
    }

    private function apply(User $as, UploadedFile $file, string $key = 'batch-key-0001', array $extra = [])
    {
        return $this->actingAs($as)->post('/api/v1/catalog/imports', ['file' => $file, 'idempotency_key' => $key] + $extra, ['Accept' => 'application/json']);
    }

    public function test_preview_validates_rows_without_writing_anything_and_reports_row_level_errors(): void
    {
        $b = $this->branch();
        Product::create(['sku' => 'EX-1', 'name' => 'Existing', 'slug' => 'existing', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);

        $file = $this->csv(
            'simple,NEW-1,,Kue Baru,,draft,15000,200,,',
            'simple,EX-1,,,,,1500,,,',
            'simple,BAD-1,,Bad,,draft,-5,200,,',
            'simple,NEW-2,,,,,,,,',
        );
        $r = $this->actingAs($b['admin'])->post('/api/v1/catalog/imports/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk();

        $rows = collect($r->json('data.rows'))->keyBy('sku');
        $this->assertSame('create', $rows['NEW-1']['action']);
        $this->assertSame('update', $rows['EX-1']['action']);
        $this->assertContains('price_invalid', $rows['BAD-1']['errors']);
        $this->assertContains('name_required', $rows['NEW-2']['errors']);
        $this->assertSame(2, $r->json('data.summary.valid'));
        $this->assertSame(2, $r->json('data.summary.invalid'));
        $this->assertSame(1, Product::count()); // nothing written
        $this->assertSame('1000.00', Product::where('sku', 'EX-1')->value('base_price'));
    }

    public function test_apply_is_atomic_per_row_and_existing_sku_is_an_update_never_a_duplicate(): void
    {
        $b = $this->branch();
        Product::create(['sku' => 'EX-1', 'name' => 'Existing', 'slug' => 'existing', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);

        $r = $this->apply($b['agent'], $this->csv(
            'simple,NEW-1,,Kue Baru,,active,15000,200,,',
            'simple,EX-1,,,,,2500,,,',
            'simple,BAD-1,,Bad,,draft,abc,200,,',
        ))->assertOk();

        $this->assertSame(['catalog_created', 'catalog_updated', 'rejected'], array_column($r->json('data.rows'), 'outcome'));
        $this->assertSame('active', Product::where('sku', 'NEW-1')->value('status'));
        $this->assertSame('2500.00', Product::where('sku', 'EX-1')->value('base_price'));
        $this->assertSame('Existing', Product::where('sku', 'EX-1')->value('name')); // omitted cell preserves
        $this->assertSame(0, Product::where('sku', 'BAD-1')->count());
        $this->assertSame(1, DB::table('catalog_skus')->where('sku', 'EX-1')->count());

        // re-applying the same file is stable (duplicate=update)
        $this->apply($b['agent'], $this->csv('simple,NEW-1,,Kue Baru,,active,15000,200,,'), 'batch-key-0002')->assertOk();
        $this->assertSame(1, Product::where('sku', 'NEW-1')->count());
    }

    public function test_sku_owner_rules_duplicate_in_file_and_variant_flow(): void
    {
        $b = $this->branch();
        $parent = Product::create(['name' => 'Par', 'slug' => 'par', 'has_variations' => true, 'status' => 'active']);
        Product::create(['sku' => 'SIMP', 'name' => 'S', 'slug' => 's', 'has_variations' => false, 'base_price' => 10, 'weight_grams' => 1, 'status' => 'active']);

        $r = $this->apply($b['agent'], $this->csv(
            'variant,VAR-1,par,,,active,5000,100,Ukuran=S,',
            'variant,SIMP,par,,,active,5000,100,Ukuran=M,',   // SKU belongs to a simple product
            'variant,VAR-9,missing,,,active,5000,100,Ukuran=M,',
            'simple,DUP,,A,,draft,1,1,,',
            'simple,dup,,B,,draft,1,1,,',                     // repeated normalised SKU → both rejected
        ))->assertOk();
        $out = collect($r->json('data.rows'));
        $this->assertSame('catalog_created', $out[0]['outcome']);
        $this->assertContains('sku_owner_type_mismatch', $out[1]['errors']);
        $this->assertContains('parent_not_found', $out[2]['errors']);
        $this->assertContains('duplicate_sku_in_file', $out[3]['errors']);
        $this->assertContains('duplicate_sku_in_file', $out[4]['errors']);
        $this->assertSame(0, Product::whereIn('sku', ['DUP', 'dup'])->count());

        // attribute edit through the file updates only that variant
        $this->apply($b['agent'], $this->csv('variant,VAR-1,par,,,,,,Ukuran=XL,'), 'batch-key-0003')->assertOk();
        $this->assertSame('XL', ProductVariation::where('sku', 'VAR-1')->first()->label());
    }

    public function test_stock_rows_are_staged_publication_withheld_until_admin_approves_and_stock_changes_only_through_opname(): void
    {
        $b = $this->branch();
        $existing = Product::create(['sku' => 'EX-2', 'name' => 'Lama', 'slug' => 'lama', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $existing->id, 'stock_type' => 'transit', 'quantity' => 5]);

        $r = $this->apply($b['admin'], $this->csv(
            'simple,EX-2,,Nama Baru,,,9999,,,12',
            'simple,NEW-S,,Kue Stok,,active,7000,100,,20',
        ), 'batch-key-stock', ['stock_type' => 'transit'])->assertOk();
        $this->assertSame(['catalog_withheld_stock_pending', 'catalog_withheld_stock_pending'], array_column($r->json('data.rows'), 'outcome'));

        // withheld: existing metadata unchanged, new product is NOT active, no stock movement, no balance written
        $this->assertSame('1000.00', $existing->fresh()->base_price);
        $this->assertSame('draft', Product::where('sku', 'NEW-S')->value('status'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(5, WarehouseStock::where('product_id', $existing->id)->value('quantity'));
        $this->getJson('/api/v1/products/'.Product::where('sku', 'NEW-S')->value('slug'))->assertStatus(404);

        $item = StockOpnameItem::where('product_id', $existing->id)->firstOrFail();
        $this->assertSame(12, $item->import_target_quantity);
        $this->assertNull($item->counted_quantity);
        $opnameId = $item->stock_opname_id;

        // retry with the same key: same document, no second opname
        $this->apply($b['admin'], $this->csv('simple,EX-2,,Nama Baru,,,9999,,,12'), 'batch-key-stock', ['stock_type' => 'transit'])->assertOk();
        $this->assertSame(1, StockOpnameItem::where('product_id', $existing->id)->count());
        // same key, different payload → conflict row, nothing created
        $c = $this->apply($b['admin'], $this->csv('simple,EX-2,,Nama Baru,,,9999,,,13'), 'batch-key-stock', ['stock_type' => 'transit'])->assertOk();
        $this->assertContains('idempotency_conflict', $c->json('data.rows.0.errors'));
        $this->assertSame(1, StockOpnameItem::where('product_id', $existing->id)->count());

        // Gudang counts/submits, Admin approves → stock + catalog publish in one commit
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$opnameId}/count", ['counts' => [['item_id' => $item->id, 'counted_quantity' => 12]]])->assertOk();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$opnameId}/submit")->assertOk();
        $this->assertSame('1000.00', $existing->fresh()->base_price); // still withheld while submitted
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$opnameId}/approve")->assertOk();

        $this->assertSame('9999.00', $existing->fresh()->base_price);
        $this->assertSame('Nama Baru', $existing->fresh()->name);
        $this->assertSame(12, WarehouseStock::where('product_id', $existing->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('opname_id', $opnameId)->count());
        $this->assertNotNull($item->fresh()->catalog_published_at);
        // the other row's product is still draft — approval is per row
        $this->assertSame('draft', Product::where('sku', 'NEW-S')->value('status'));
    }

    public function test_stale_stock_blocks_approval_with_409_and_publishes_nothing(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'ST-1', 'name' => 'Stale', 'slug' => 'stale', 'has_variations' => false, 'base_price' => 100, 'weight_grams' => 10, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $this->apply($b['agent'], $this->csv('simple,ST-1,,,,,555,,,9'), 'batch-key-stale', ['stock_type' => 'transit'])->assertOk();
        $item = StockOpnameItem::where('product_id', $p->id)->firstOrFail();
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$item->stock_opname_id}/count", ['counts' => [['item_id' => $item->id, 'counted_quantity' => 9]]])->assertOk();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$item->stock_opname_id}/submit")->assertOk();

        WarehouseStock::where('product_id', $p->id)->update(['quantity' => 7]); // concurrent physical change

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$item->stock_opname_id}/approve")->assertStatus(409);
        $this->assertSame('100.00', $p->fresh()->base_price);
        $this->assertNull($item->fresh()->catalog_published_at);
        $this->assertSame(7, WarehouseStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_stock_context_authority_super_admin_needs_explicit_agent_and_cross_agent_is_denied(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $sa = User::factory()->superAdmin()->create();
        $p = Product::create(['sku' => 'CX-1', 'name' => 'X', 'slug' => 'x', 'has_variations' => false, 'base_price' => 100, 'weight_grams' => 10, 'status' => 'active']);
        $f = fn () => $this->csv('simple,CX-1,,,,,300,,,4');

        $r = $this->apply($sa, $f(), 'batch-key-sa-01', ['stock_type' => 'transit'])->assertOk(); // no agent_id
        $this->assertContains('stock_context_invalid', $r->json('data.rows.0.errors'));
        $r = $this->apply($b['agent'], $f(), 'batch-key-sa-02', ['stock_type' => 'transit', 'agent_id' => $other['agent']->id])->assertOk();
        $this->assertContains('stock_context_invalid', $r->json('data.rows.0.errors'));
        $this->assertSame(0, StockOpnameItem::count());

        $this->apply($sa, $f(), 'batch-key-sa-03', ['stock_type' => 'transit', 'agent_id' => $b['agent']->id])->assertOk();
        $this->assertSame($b['agent']->id, StockOpnameItem::firstOrFail()->opname->agent_id);

        // a soft-deleted agent is invalid; a konsumen/gudang cannot import at all
        $b['agent']->delete();
        $r = $this->apply($sa, $f(), 'batch-key-sa-04', ['stock_type' => 'transit', 'agent_id' => $b['agent']->id])->assertOk();
        $this->assertContains('stock_context_invalid', $r->json('data.rows.0.errors'));
        $this->actingAs($other['gudang'])->post('/api/v1/catalog/imports/preview', ['file' => $f()], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_file_safety_rejects_binary_formula_xlsx_oversize_and_unknown_columns(): void
    {
        $b = $this->branch();
        $post = fn (UploadedFile $f) => $this->actingAs($b['agent'])->post('/api/v1/catalog/imports/preview', ['file' => $f], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->createWithContent('x.csv', "type,sku\n\0\0binary"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.csv', "type,sku,evil\nsimple,A,1\n"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.csv', "type,sku\n"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.csv', self::H."\n".str_repeat("simple,A,,,,,,,,\n", 501)))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.csv', self::H."\nsimple,".str_repeat('A', 600).",,,,,,,,\n"))->assertStatus(422);

        // XLSX carrying a formula is refused
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([explode(',', self::H), ['simple', 'F-1', '', 'N', '', 'draft', '=1+1', '1', '', '']]);
        $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new Xlsx($book))->save($path);
        $post(new UploadedFile($path, 'f.xlsx', null, null, true))->assertStatus(422);

        // a clean XLSX is accepted (values only)
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([explode(',', self::H), ['simple', 'F-2', '', 'Nama', '', 'draft', '10', '5', '', '']]);
        $path2 = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new Xlsx($book))->save($path2);
        $r = $post(new UploadedFile($path2, 'g.xlsx', null, null, true))->assertOk();
        $this->assertSame('create', $r->json('data.rows.0.action'));
    }

    public function test_export_roundtrips_neutralises_formulas_and_never_sums_stock_contexts(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => '=HYPERLINK("x")', 'name' => '+evil', 'slug' => 'evil', 'has_variations' => false, 'base_price' => 100, 'weight_grams' => 10, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 3]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'shipping', 'quantity' => 8]);

        $csv = $this->actingAs($b['agent'])->get('/api/v1/catalog/export?stock_type=transit')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+evil", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertMatchesRegularExpression('/,3\s*$/m', $csv); // transit only — not 11

        $this->actingAs($b['agent'])->get('/api/v1/catalog/export?stock_type=transit&agent_id='.($b['agent']->id + 999))->assertStatus(403);
        $this->actingAs($b['agent'])->get('/api/v1/catalog/export?format=xlsx')->assertOk();
        $this->actingAs($b['gudang'])->get('/api/v1/catalog/export')->assertStatus(403);

        // exported CSV re-imports as an update of the same product, not a new one
        $file = UploadedFile::fake()->createWithContent('rt.csv', $csv);
        $r = $this->actingAs($b['agent'])->post('/api/v1/catalog/imports/preview', ['file' => $file, 'stock_type' => 'transit'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('update', $r->json('data.rows.0.action'));
    }
    public function test_quantity_aba_rejects_approval_without_adjustment_or_publication(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'ABA', 'name' => 'Original', 'slug' => 'aba', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);
        $stock = WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $this->apply($b['admin'], $this->csv('simple,ABA,,Changed,,,2000,,,9'), 'aba-batch-0001', ['stock_type' => 'transit'])->assertOk();
        $item = StockOpnameItem::where('product_id', $p->id)->firstOrFail();
        // Use the canonical service to keep the regression independent of route verbs.
        $service = app(\App\Services\Stock\StockOpnameService::class);
        $service->count($b['gudang'], $item->opname, [['item_id' => $item->id, 'counted_quantity' => 9]]);
        $service->submit($b['gudang'], $item->opname->fresh());
        DB::table('warehouse_stocks')->where('id', $stock->id)->increment('quantity', 3);
        DB::update('UPDATE warehouse_stocks SET quantity = quantity - 3 WHERE id = ?', [$stock->id]);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame($item->system_stock_revision + 2, app(\App\Services\Stock\PhysicalStockRevision::class)->current($b['agent']->id, 'transit', null, $p->id, null));
        $this->actingAs($b['admin'])->postJson('/api/v1/warehouse/opnames/'.$item->stock_opname_id.'/approve')->assertStatus(409);
        $this->assertSame('1000.00', $p->fresh()->base_price);
        $this->assertNull($item->fresh()->catalog_published_at);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_revision_tracks_model_and_sql_writes_and_rolls_back_with_quantity(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'REV', 'name' => 'Revision', 'slug' => 'rev', 'has_variations' => false, 'base_price' => 1, 'weight_grams' => 10, 'status' => 'active']);
        $stock = WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 5]);
        $revision = fn () => app(\App\Services\Stock\PhysicalStockRevision::class)->current($b['agent']->id, 'transit', null, $p->id, null);
        $before = $revision();
        $stock->update(['quantity' => 6]);
        $stock->increment('quantity');
        $stock->decrement('quantity');
        $this->assertSame($before + 3, $revision());
        DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 6]);
        $this->assertSame($before + 3, $revision(), 'no-op cannot forge a revision');
        DB::beginTransaction();
        DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 10]);
        DB::rollBack();
        $this->assertSame(6, $stock->fresh()->quantity);
        $this->assertSame($before + 3, $revision());
    }
    public function test_absent_stock_insert_delete_aba_does_not_change_legacy_fallback_and_rejects_approval(): void
    {
        $b = $this->branch();
        $p = Product::create(['sku' => 'MISSING-ABA', 'name' => 'Legacy', 'slug' => 'missing-aba', 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active']);
        \App\Models\ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $this->apply($b['admin'], $this->csv('simple,MISSING-ABA,,Changed,,,2000,,,9'), 'missing-aba-0001', ['stock_type' => 'transit'])->assertOk();
        $this->assertSame(0, WarehouseStock::count(), 'staging must preserve absence/legacy stock fallback');
        $item = StockOpnameItem::where('product_id', $p->id)->firstOrFail();
        $service = app(\App\Services\Stock\StockOpnameService::class);
        $service->count($b['gudang'], $item->opname, [['item_id' => $item->id, 'counted_quantity' => 9]]);
        $service->submit($b['gudang'], $item->opname->fresh());
        $stock = WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 4]);
        $stock->delete();
        $this->assertSame(0, WarehouseStock::count());
        $this->actingAs($b['admin'])->postJson('/api/v1/warehouse/opnames/'.$item->stock_opname_id.'/approve')->assertStatus(409);
        $this->assertSame('1000.00', $p->fresh()->base_price);
        $this->assertSame(20, \App\Models\ProductStock::where('product_id', $p->id)->value('quantity_on_hand'));
        $this->assertSame(0, StockMovement::count());
    }
}
