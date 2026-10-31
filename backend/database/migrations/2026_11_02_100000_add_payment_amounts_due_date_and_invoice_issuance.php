<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER V2 (Improvements 05–11, 13) — strictly ADDITIVE, nullable/defaulted, no backfill of
 * historical financial rows (AGENTS.md §9).
 *
 *  - bank_transfer_verifications: the submitted amount stays on payment_transactions.amount and is
 *    mirrored in submitted_amount; the amount Keuangan actually confirmed is stored separately in
 *    verified_amount with a mandatory reason when it differs. Cash receipts reuse the same
 *    verification pipeline (channel = cash) with receiver + receipt reference. proof_disk records
 *    where a proof lives so legacy `public` files keep resolving while new ones are private.
 *  - orders.due_date: due date PER ORDER; every change is audited in order_due_date_changes.
 *  - orders.invoice_issued_at + order_invoice_snapshots: the invoice number is still orders.order_no;
 *    issuance is timestamped once and each balance state is kept as an immutable, versioned snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transfer_verifications', function (Blueprint $table) {
            $table->string('channel', 16)->default('transfer');
            $table->decimal('submitted_amount', 12, 2)->nullable();
            $table->decimal('verified_amount', 12, 2)->nullable();
            $table->string('amount_adjustment_reason', 255)->nullable();
            $table->foreignId('cash_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cash_received_at')->nullable();
            $table->string('receipt_reference', 120)->nullable();
            $table->string('proof_disk', 16)->default('public');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->date('due_date')->nullable();
            $table->timestamp('invoice_issued_at')->nullable();
        });

        Schema::create('order_due_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->date('old_due_date')->nullable();
            $table->date('new_due_date')->nullable();
            $table->string('reason', 255);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('order_invoice_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('trigger', 40);
            $table->string('invoice_number', 40);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('verified_paid_amount', 12, 2);
            $table->decimal('outstanding_amount', 12, 2);
            $table->string('payment_status', 30);
            $table->date('due_date')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoice_snapshots');
        Schema::dropIfExists('order_due_date_changes');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'invoice_issued_at']);
        });

        Schema::table('bank_transfer_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_received_by_user_id');
            $table->dropColumn(['channel', 'submitted_amount', 'verified_amount', 'amount_adjustment_reason', 'cash_received_at', 'receipt_reference', 'proof_disk']);
        });
    }
};
