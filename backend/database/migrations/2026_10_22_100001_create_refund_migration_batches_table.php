<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill batch provenance. Created before the snapshot tables because
 * order_financial_snapshots.migration_batch_id references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_key', 120)->unique();
            $table->enum('mode', ['dry_run', 'apply']);
            $table->enum('status', ['dry_run', 'running', 'completed', 'failed', 'partially_completed'])->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_migration_batches');
    }
};
