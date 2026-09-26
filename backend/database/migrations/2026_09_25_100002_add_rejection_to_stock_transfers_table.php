<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_transfers MODIFY status ENUM('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'pending'");
        }
        Schema::table('stock_transfers', function ($table) {
            $table->foreignId('rejected_by')->nullable()->after('completed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function ($table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_transfers MODIFY status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
