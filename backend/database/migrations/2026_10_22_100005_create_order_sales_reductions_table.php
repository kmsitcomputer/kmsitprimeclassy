<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Net-basis sales reductions (returns and fulfillment shortfalls). applied_to_order_total=false
 * rows are the only ones subtracted in I6 (fulfillment reductions already folded into
 * orders.total_amount are never subtracted twice). Reinstatement appends audit fields; the row
 * is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sales_reductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('source_type', ['return_stage', 'fulfillment_shortfall', 'cancellation', 'reinstatement']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 14, 2)->unsigned();
            $table->boolean('applied_to_order_total')->default(false);
            $table->timestamp('reinstated_at')->nullable();
            $table->foreignId('reinstated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reinstatement_reason', 255)->nullable();
            $table->string('reinstatement_reconciliation_ref', 191)->nullable();
            $table->enum('provenance', ['forward', 'legacy', 'provisional_legacy'])->default('forward');
            $table->timestamps();

            $table->index(['order_id', 'source_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sales_reductions');
    }
};
