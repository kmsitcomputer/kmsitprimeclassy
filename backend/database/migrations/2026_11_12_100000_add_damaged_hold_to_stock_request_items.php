<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F07 — units a Koordinator receipt reported DAMAGED stay in Transit with their reservation, but must not
 * silently return to the sellable pool when demand shrinks. `damaged_hold_qty` is the unresolved part of the
 * line's outstanding demand: it cannot be re-prepared, released or cancelled until an Admin resolves it
 * (restock / write_off). Additive, default 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_items', function (Blueprint $table) {
            $table->unsignedInteger('damaged_hold_qty')->default(0)->after('remaining_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_items', function (Blueprint $table) {
            $table->dropColumn('damaged_hold_qty');
        });
    }
};
