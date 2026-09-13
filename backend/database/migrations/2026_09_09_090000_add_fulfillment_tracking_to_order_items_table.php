<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item status tracking mirrors the order's own 7-state lifecycle exactly
 * (Blueprint: "status harus diperiksa PER ORDER ITEM") — an item can diverge
 * from the order's overall status (e.g. one item 'dibatalkan' at the
 * fulfillment stage while the rest of the order continues to 'dikirim').
 *
 * The four new quantity columns are running totals, always updated inside
 * the same DB transaction as the ledger row that caused them
 * (OrderItemAdjustment for cancelled/refund, ReturnItem for returned) — kept
 * denormalized here so reads never need to aggregate those tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_quantity')->default(0)->after('fulfilled_quantity');
            $table->unsignedInteger('returned_quantity')->default(0)->after('cancelled_quantity');
            $table->unsignedInteger('refund_quantity')->default(0)->after('returned_quantity');
            $table->unsignedInteger('additional_quantity')->default(0)->after('refund_quantity');
        });

        DB::statement(
            "ALTER TABLE order_items MODIFY status ENUM('diterima','diproses','dikirim','terkirim','dibatalkan','pengembalian','kembali') NOT NULL DEFAULT 'diterima'"
        );
        DB::table('order_items')->where('status', 'pending')->update(['status' => 'diterima']);
        DB::table('order_items')->where('status', 'processing')->update(['status' => 'diproses']);
        DB::table('order_items')->where('status', 'fulfilled')->update(['status' => 'terkirim']);
        DB::table('order_items')->whereIn('status', ['partially_returned', 'returned'])->update(['status' => 'pengembalian']);
        DB::table('order_items')->where('status', 'cancelled')->update(['status' => 'dibatalkan']);
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE order_items MODIFY status ENUM('pending','processing','fulfilled','partially_returned','returned','cancelled') NOT NULL DEFAULT 'pending'"
        );

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cancelled_quantity', 'returned_quantity', 'refund_quantity', 'additional_quantity']);
        });
    }
};
