<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund ledger v1 — additive columns on the existing order domain.
 *
 * gross_received_amount is the "G" of the financial model (all verified money in) and never
 * decreases on refunds. cancellation_financial_status separates the order-cancellation request
 * from the financial finalization that may have to wait for in-flight payout attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('gross_received_amount', 14, 2)->unsigned()->default(0)->after('paid_amount');
            $table->enum('cancellation_financial_status', ['not_applicable', 'pending_reconciliation', 'finalized'])
                ->default('not_applicable')->after('cancellation_reason');
            $table->timestamp('cancellation_finalized_at')->nullable()->after('cancellation_financial_status');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('handed_over_at')->nullable()->after('fulfilled_quantity');
            $table->index('handed_over_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['handed_over_at']);
            $table->dropColumn('handed_over_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gross_received_amount', 'cancellation_financial_status', 'cancellation_finalized_at']);
        });
    }
};
