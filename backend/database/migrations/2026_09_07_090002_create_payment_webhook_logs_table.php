<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable receipt of every webhook call this app has ever gotten —
        // the durable record that makes replay-safety and idempotency
        // auditable after the fact, not just enforced silently in memory.
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            // The provider's own event/transaction id (Stripe 'evt_...', Xendit
            // invoice id, Tripay reference) — the natural idempotency key: the
            // same event delivered twice (a gateway retry, a replayed request)
            // hits the same row via this unique index instead of double-processing.
            $table->string('event_id', 150);
            $table->string('gateway_reference', 100)->nullable();
            $table->json('headers');
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_method_id', 'event_id']);
            $table->index('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
