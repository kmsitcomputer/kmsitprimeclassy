<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G17-D4: a refund on a CRM-origin order (no account to pay back to) needs recorded evidence of WHO
 * receives the money, by WHICH method, and under WHAT authorization. Append-only; a correction is a
 * new row that `supersedes_id` the old one. The refund ledger itself is not touched by this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_recipient_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('crm_customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('return_id')->nullable()->constrained('returns')->restrictOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_phone', 30)->nullable();
            $table->string('recipient_relation', 100)->nullable();
            $table->string('method', 30);
            // Bank/e-wallet details; encrypted at rest by the model cast.
            $table->text('destination_details')->nullable();
            $table->foreignId('consent_event_id')->nullable()->constrained('consent_events')->restrictOnDelete();
            $table->text('authorization_note');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('refund_recipient_evidences')->restrictOnDelete();
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_recipient_evidences');
    }
};
