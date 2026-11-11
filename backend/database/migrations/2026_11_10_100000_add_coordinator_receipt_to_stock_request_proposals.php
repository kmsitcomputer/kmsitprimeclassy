<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-002 — dual physical handover (Gudang prepares/hands over → Koordinator Kurir receives).
 *
 * Additive only. The Gudang proposal already represents "prepared & handed over" quantity; the
 * Koordinator's receipt now settles it. Receipt differentiates accepted / short / damaged per
 * proposal item. Only the accepted quantity moves stock (Transit → Shipping, reservation consumed)
 * and increments StockRequestItem.fulfilled_qty, so "accepted" is the single authoritative figure
 * every downstream gate (courier assignment, batch accounting) reads.
 *
 * Existing rows: NULL receipt columns = settled by the legacy Admin approval (all quantity accepted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_proposals', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->string('receipt_note', 255)->nullable()->after('received_at');
        });

        Schema::table('stock_request_proposal_items', function (Blueprint $table) {
            $table->unsignedInteger('accepted_qty')->nullable()->after('quantity');
            $table->unsignedInteger('short_qty')->nullable()->after('accepted_qty');
            $table->unsignedInteger('damaged_qty')->nullable()->after('short_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_proposal_items', function (Blueprint $table) {
            $table->dropColumn(['accepted_qty', 'short_qty', 'damaged_qty']);
        });

        Schema::table('stock_request_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['received_at', 'receipt_note']);
        });
    }
};
