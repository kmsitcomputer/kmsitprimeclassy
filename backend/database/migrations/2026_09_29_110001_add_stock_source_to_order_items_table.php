<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-02: persist WHICH stock domain an order item consumes.
 *   stock_source = 'agent' (default; every existing row)  -> sub_location_id must be NULL
 *   stock_source = 'sub'                                   -> sub_location_id is required
 * Additive: existing rows are backfilled by the column default, no data rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->enum('stock_source', ['agent', 'sub'])->default('agent')->after('product_variation_id');
            $table->foreignId('sub_location_id')->nullable()->after('stock_source')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->index(['stock_source', 'sub_location_id'], 'order_items_stock_source_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_stock_source_consistent CHECK ((stock_source = 'sub' AND sub_location_id IS NOT NULL) OR (stock_source = 'agent' AND sub_location_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_stock_source_consistent');
        }
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['sub_location_id']);
            $table->dropIndex('order_items_stock_source_index');
            $table->dropColumn(['stock_source', 'sub_location_id']);
        });
    }
};
