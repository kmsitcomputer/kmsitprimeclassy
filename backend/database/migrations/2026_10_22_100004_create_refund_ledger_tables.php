<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three-layer refund ledger: obligation (money lawfully owed) -> attempt (every disbursement
 * attempt, including failed/unknown) -> payout (verified money that actually left). Plus the
 * append-only audit stream. Rows are never deleted.
 *
 * amount/deferred constraints are enforced in the application layer (MySQL CHECK portability;
 * design 10 §13.6), not by CHECK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_obligations', function (Blueprint $table) {
            $table->id();
            $table->string('obligation_no', 40)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('source_type', [
                'return_stage',
                'fulfillment_shortfall',
                'cancellation',
                'manual',
                'legacy',
                'deferred',
            ]);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('obligation_key', 191)->unique();
            $table->decimal('amount', 14, 2)->unsigned()->default(0);
            $table->enum('status', [
                'pending',
                'approved',
                'partially_paid',
                'completed',
                'needs_verification',
                'cancelled',
            ])->default('pending');
            $table->enum('provenance', ['forward', 'legacy', 'provisional_legacy'])->default('forward');
            $table->string('reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->enum('approval_source', ['manual', 'auto_cancellation_policy', 'owner_manual_correction', 'fulfillment_reduction', 'return_approval', 'legacy_endpoint'])->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('snapshot_id')->nullable()
                ->constrained('order_financial_snapshots')->nullOnDelete();
            $table->decimal('deferred_amount', 14, 2)->unsigned()->default(0);
            $table->decimal('deferred_remaining', 14, 2)->unsigned()->default(0);
            $table->text('deferred_reason')->nullable();
            $table->foreignId('parent_obligation_id')->nullable()
                ->constrained('refund_obligations')->restrictOnDelete();
            $table->foreignId('migration_batch_id')->nullable()
                ->constrained('refund_migration_batches')->nullOnDelete();
            $table->string('legacy_source_type', 60)->nullable();
            $table->unsignedBigInteger('legacy_source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index(['order_id', 'provenance']);
        });

        Schema::create('refund_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('refund_obligations')->restrictOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->enum('method', ['bank_transfer', 'gateway', 'cash']);
            $table->decimal('amount', 14, 2)->unsigned();
            $table->enum('status', ['created', 'processing', 'succeeded', 'failed', 'unknown', 'cancelled'])
                ->default('created');
            $table->string('idempotency_key', 191)->unique();
            $table->string('external_reference', 191)->nullable();
            $table->string('provider_event_id', 191)->nullable()->unique();
            $table->foreignId('payment_transaction_id')->nullable()
                ->constrained('payment_transactions')->nullOnDelete();
            $table->json('destination_snapshot')->nullable();
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->string('proof_type', 60)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_message')->nullable();
            $table->text('reconciliation_evidence')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('unknown_since')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['obligation_id', 'attempt_no'], 'refund_attempt_no_unique');
            $table->index(['obligation_id', 'status']);
            $table->index('status');
        });

        Schema::create('refund_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('payout_no', 40)->unique();
            $table->foreignId('obligation_id')->constrained('refund_obligations')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('attempt_id')->unique()->constrained('refund_attempts')->restrictOnDelete();
            $table->string('payout_key', 191)->unique();
            $table->decimal('amount', 14, 2)->unsigned();
            $table->enum('method', ['bank_transfer', 'gateway', 'cash']);
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->string('proof_type', 60)->nullable();
            $table->string('gateway_reference', 191)->nullable();
            $table->enum('verification_status', ['proven', 'legacy_unverified'])->default('legacy_unverified');
            $table->boolean('affects_paid_amount')->default(true);
            $table->timestamp('paid_effect_applied_at')->nullable();
            $table->boolean('recorded_on_cancelled_obligation')->default(false);
            $table->foreignId('migration_batch_id')->nullable()
                ->constrained('refund_migration_batches')->nullOnDelete();
            $table->string('legacy_source', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['verification_status', 'affects_paid_amount'], 'refund_payouts_proven_idx');
            $table->index('paid_at');
        });

        Schema::create('refund_ledger_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('obligation_id')->nullable()->constrained('refund_obligations')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('refund_attempts')->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('refund_payouts')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('event_key', 191)->nullable()->unique();
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['order_id', 'occurred_at']);
            $table->index(['obligation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_ledger_events');
        Schema::dropIfExists('refund_payouts');
        Schema::dropIfExists('refund_attempts');
        Schema::dropIfExists('refund_obligations');
    }
};
