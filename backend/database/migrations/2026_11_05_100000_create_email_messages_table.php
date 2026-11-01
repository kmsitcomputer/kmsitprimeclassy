<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional e-mail delivery log + idempotency ledger (order / payment / shipping / return / refund).
 * ADDITIVE ONLY: one new table, no existing table or column is touched.
 *
 * - `idempotency_key` is UNIQUE: one business event yields at most one row per recipient account.
 * - `status`: queued | sent | failed | skipped. No automatic retry (the job runs once); a failure is visible here.
 * - `body` is the rendered text; it never holds an OTP, a password or a token (those never ride this lane).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('event', 50);
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('recipient_role', 20);                 // admin | creator
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_email', 191);
            $table->string('subject', 191);
            $table->text('body');
            $table->string('status', 20)->default('queued');
            $table->string('error_class', 60)->nullable();
            $table->string('error_message', 255)->nullable();   // sanitized
            $table->string('skip_reason', 60)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
