<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->foreignId('sub_location_id')->nullable()->after('stock_type')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->index(['agent_id', 'sub_location_id', 'product_id', 'product_variation_id'], 'warehouse_sub_location_target');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sub_location_id')->nullable()->after('stock_type')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->index(['agent_id', 'sub_location_id', 'transfer_id'], 'stock_movements_sub_location');
        });
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('source_sub_location_id')->nullable()->after('source_stock_type')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->foreignId('destination_sub_location_id')->nullable()->after('destination_stock_type')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->index(['agent_id', 'source_sub_location_id', 'destination_sub_location_id'], 'stock_transfers_sub_locations');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE warehouse_stocks ADD target_identity VARCHAR(80) GENERATED ALWAYS AS (CONCAT(IF(product_id IS NULL, 'v:', 'p:'), IFNULL(product_id, product_variation_id))) STORED");
            DB::statement('ALTER TABLE warehouse_stocks ADD location_identity BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(sub_location_id, 0)) STORED');
            DB::statement('ALTER TABLE warehouse_stocks ADD UNIQUE warehouse_stocks_target_location_unique (agent_id, stock_type, location_identity, target_identity)');
            DB::statement("ALTER TABLE warehouse_stocks ADD CONSTRAINT chk_warehouse_sub_location_type CHECK ((stock_type = 'sub' AND sub_location_id IS NOT NULL) OR (stock_type <> 'sub' AND sub_location_id IS NULL))");
        } else {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->unique(['agent_id', 'stock_type', 'sub_location_id', 'product_id', 'product_variation_id'], 'warehouse_stocks_target_location_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE warehouse_stocks DROP INDEX warehouse_stocks_target_location_unique');
            DB::statement('ALTER TABLE warehouse_stocks DROP CHECK chk_warehouse_sub_location_type');
            DB::statement('ALTER TABLE warehouse_stocks DROP COLUMN location_identity, DROP COLUMN target_identity');
        } else {
            Schema::table('warehouse_stocks', fn (Blueprint $table) => $table->dropUnique('warehouse_stocks_target_location_unique'));
        }
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex('stock_transfers_sub_locations');
            $table->dropForeign(['source_sub_location_id']);
            $table->dropForeign(['destination_sub_location_id']);
            $table->dropColumn(['source_sub_location_id', 'destination_sub_location_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_sub_location');
            $table->dropForeign(['sub_location_id']);
            $table->dropColumn('sub_location_id');
        });
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->dropIndex('warehouse_sub_location_target');
            $table->dropForeign(['sub_location_id']);
            $table->dropColumn('sub_location_id');
        });
    }
};
