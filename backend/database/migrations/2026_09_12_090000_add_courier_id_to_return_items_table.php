<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Status order request pengembalian bisa dilihat oleh semua kurir, setelah
 * ada kurir yang pickup... tidak boleh tampil di kurir lain" — the same
 * claim-on-first-action pattern as shipments.courier_id
 * (CourierService::selfAssignIfUnassigned), applied per return item so one
 * kurir picking up a return doesn't block a sibling return item on the
 * same request that nobody has claimed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->after('order_item_id')
                ->constrained('couriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_id');
        });
    }
};
