<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line projection of the order-wide snapshot (B-01). Created in the snapshot transaction;
 * never recomputed from current quantities. UNIQUE(order_item_id) encodes "one basis per line
 * in the single-snapshot model".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_financial_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('snapshot_id')->constrained('order_financial_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('qty_basis');
            $table->decimal('unit_gross_basis', 14, 2)->unsigned();
            $table->decimal('line_gross_basis', 14, 2)->unsigned();
            $table->decimal('allocated_discount_total', 14, 2)->unsigned()->default(0);
            $table->decimal('line_net_basis', 14, 2)->unsigned();
            $table->unsignedInteger('consumed_units_count')->default(0);
            $table->decimal('consumed_gross_total', 14, 2)->unsigned()->default(0);
            $table->decimal('consumed_discount_total', 14, 2)->unsigned()->default(0);
            $table->decimal('consumed_net_total', 14, 2)->unsigned()->default(0);
            $table->char('allocation_checksum', 64);
            $table->timestamp('frozen_at');
            $table->enum('provenance', ['frozen', 'provisional_legacy'])->default('frozen');
            $table->foreignId('migration_batch_id')->nullable()
                ->constrained('refund_migration_batches')->nullOnDelete();
            $table->timestamps();

            $table->unique(['snapshot_id', 'order_item_id'], 'oifb_snapshot_item_unique');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_financial_bases');
    }
};
