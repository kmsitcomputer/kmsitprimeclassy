<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns payment_transactions.status with the exact status set the payment
 * architecture contract requires: pending, paid, failed, expired, cancelled,
 * refunded. 'success' becomes 'paid'; 'processing' (previously used for "bank
 * transfer proof submitted, awaiting verification") collapses into 'pending'
 * — that in-between state is tracked on orders.payment_status
 * ('pending_verification'), not on the transaction itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_transactions')->where('status', 'success')->update(['status' => 'paid']);
        DB::table('payment_transactions')->where('status', 'processing')->update(['status' => 'pending']);

        DB::statement(
            "ALTER TABLE payment_transactions MODIFY status ENUM('pending','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE payment_transactions MODIFY status ENUM('pending','processing','success','failed','expired','cancelled') NOT NULL DEFAULT 'pending'"
        );
        DB::table('payment_transactions')->where('status', 'paid')->update(['status' => 'success']);
    }
};
