<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-01: one immutable order-wide financial allocation snapshot per order.
 *
 * The snapshot is the single allocation truth. Per-line bases (next migration) are projections
 * created in the same transaction; later basis-requiring events REUSE the snapshot and never
 * re-allocate. UNIQUE(order_id) is the concurrency guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('snapshot_version')->default(1);
            $table->enum('trigger', [
                'first_handover',
                'return_approval',
                'fulfillment_shortfall',
                'order_cancellation',
                'provisional_legacy_backfill',
            ]);
            $table->decimal('subtotal_basis', 14, 2)->unsigned();
            $table->decimal('effective_voucher_basis', 14, 2)->unsigned()->default(0);
            $table->string('voucher_source_type', 60)->nullable();
            $table->unsignedBigInteger('voucher_source_id')->nullable();
            $table->enum('allocation_method', ['largest_remainder_unit_v1'])->default('largest_remainder_unit_v1');
            $table->char('allocation_checksum', 64);
            $table->timestamp('frozen_at');
            $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('provenance', ['frozen', 'provisional_legacy'])->default('frozen');
            $table->foreignId('migration_batch_id')->nullable()
                ->constrained('refund_migration_batches')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('frozen_at');
        });

        Schema::create('order_financial_snapshot_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('order_financial_snapshots')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('unit_index');
            $table->decimal('unit_gross', 14, 2)->unsigned();
            $table->decimal('allocated_discount', 14, 2)->unsigned()->default(0);
            // Application-enforced net (unit_gross - allocated_discount); a generated column is
            // not used so the ledger stays portable across MySQL/MariaDB setups.
            $table->decimal('unit_net', 14, 2)->unsigned();
            $table->timestamp('consumed_at')->nullable();
            $table->enum('consumed_by_type', ['return_item', 'return_approval', 'fulfillment_shortfall'])->nullable();
            $table->unsignedBigInteger('consumed_by_id')->nullable();
            $table->unsignedInteger('consumption_seq')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_id', 'order_item_id', 'unit_index'], 'ofsu_unit_unique');
            $table->index(['snapshot_id', 'order_item_id', 'consumed_at'], 'ofsu_consumption_idx');
            $table->index(['consumed_by_type', 'consumed_by_id'], 'ofsu_consumed_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_financial_snapshot_units');
        Schema::dropIfExists('order_financial_snapshots');
    }
};
