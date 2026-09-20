<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('in','out','reserve','release','adjustment','transfer_in','transfer_out','return_restock','factory_in','factory_plan_in','factory_plan_out','fulfillment','opname_adjustment','cancellation_release') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('in','out','reserve','release','adjustment','transfer_in','transfer_out','return_restock') NOT NULL");
        }
    }
};
