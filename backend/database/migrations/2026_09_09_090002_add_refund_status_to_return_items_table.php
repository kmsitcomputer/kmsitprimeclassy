<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits "has this item's return been reviewed" from "has the money actually
 * been sent back" — the admin return dashboard shows both independently
 * (Blueprint: "status. refund status."). Mirrors OrderItemAdjustment's own
 * refund_status column for the same concept elsewhere in the order engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE return_items MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('return_items', function (Blueprint $table) {
            $table->enum('refund_status', ['not_required', 'pending', 'processed', 'failed'])
                ->default('not_required')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn('refund_status');
        });

        DB::statement("ALTER TABLE return_items MODIFY status ENUM('pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending'");
    }
};
