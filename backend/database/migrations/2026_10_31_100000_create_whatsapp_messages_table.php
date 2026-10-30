<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Gateway (GOWA) delivery log + idempotency ledger. ADDITIVE ONLY: one new table, no existing table or
 * column is touched (configuration lives in the existing `settings` table, grouped `integration.whatsapp`).
 *
 * - `idempotency_key` is UNIQUE: one business event can never produce two rows, whatever retries or re-renders happen.
 * - `status` honestly reflects provider evidence: queued | sending | sent | failed | unknown | skipped.
 * - `secret_body` holds an OTP message ONLY until it is delivered/finally failed (encrypted by the model cast, wiped
 *   afterwards); `body` never contains an OTP (it is stored redacted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('purpose', 20);                       // transaction | otp | test
            $table->string('event', 50)->nullable();             // order_shipped, payment_paid, ...
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();      // order | return | refund_payout | otp_challenge
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('recipient_phone', 20)->nullable();   // msisdn digits, e.g. 62812...
            $table->text('body')->nullable();                    // rendered text; OTP codes are redacted here
            $table->text('secret_body')->nullable();             // encrypted; OTP only; wiped after the final outcome
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('provider_message_id', 100)->nullable();
            $table->string('error_class', 40)->nullable();
            $table->string('error_message', 255)->nullable();   // sanitized (no credentials, no Authorization header)
            $table->string('skip_reason', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('order_id');
            $table->index(['purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
