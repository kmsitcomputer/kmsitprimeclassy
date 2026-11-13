<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Stock\PhysicalStockRevision;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class StockRevisionMigrationTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected bool $isolatedSchemaAlwaysRebuild = true;

    public function test_additive_migrations_retry_and_reverse_without_changing_existing_balances(): void
    {
        $this->seed(RoleSeeder::class);
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);
        $p = Product::create(['sku' => 'DDL-REV', 'name' => 'Migration', 'slug' => 'ddl-rev', 'has_variations' => false, 'base_price' => 100, 'weight_grams' => 10, 'status' => 'active']);
        $stock = WarehouseStock::create(['agent_id' => $a->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 8]);
        $revision = fn () => app(PhysicalStockRevision::class)->current($a->id, 'transit', null, $p->id, null);
        $m = require database_path('migrations/2026_11_14_110000_add_physical_stock_revision.php');
        $m->up(); // retry after already-installed DDL: no duplicate trigger/counter
        $this->assertSame(1, $revision());
        $m->down();
        $this->assertFalse(Schema::hasTable('warehouse_stock_revisions'));
        $this->assertSame(8, $stock->fresh()->quantity);
        $m->up();
        $this->assertSame(0, $revision(), 'existing quantities are not reinterpreted as movements');
        DB::table('warehouse_stocks')->where('id', $stock->id)->decrement('quantity', 1);
        $this->assertSame(1, $revision());
        $this->assertSame(7, $stock->fresh()->quantity);
        $this->assertCount(3, DB::select("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'warehouse_stocks_revision_%'"));
        $this->assertSame(7, $stock->fresh()->quantity);
    }
}
