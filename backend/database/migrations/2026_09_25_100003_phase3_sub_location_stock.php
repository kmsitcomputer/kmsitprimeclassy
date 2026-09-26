<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_sub_locations', function (Blueprint $table) {
            $table->string('contact_number', 30)->nullable()->after('address');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE warehouse_stock_requests MODIFY quantity INT NOT NULL');
            DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('in','out','reserve','release','adjustment','transfer_in','transfer_out','return_restock','factory_in','factory_plan_in','factory_plan_out','fulfillment','opname_adjustment','cancellation_release','legacy_backfill','sub_adjustment') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('warehouse_sub_locations', function (Blueprint $table) {
            $table->dropColumn('contact_number');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE warehouse_stock_requests MODIFY quantity INT UNSIGNED NOT NULL');
            DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('in','out','reserve','release','adjustment','transfer_in','transfer_out','return_restock','factory_in','factory_plan_in','factory_plan_out','fulfillment','opname_adjustment','cancellation_release','legacy_backfill') NOT NULL");
        }
    }
};
