<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-02: durable Sub stock reservation ledger. Deliberately NOT the legacy Agent
 * product_stocks.quantity_reserved column — Sub stock is a separate domain.
 *
 *   Sub Reserved  = SUM(quantity) WHERE status = 'active'
 *   Sub Sellable  = warehouse_stocks(sub).quantity - Sub Reserved
 *
 * One reservation per order item (UNIQUE) makes duplicate/retried reserve calls a no-op instead
 * of a double reservation. Rows are never deleted; consumed/released keep the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sub_location_id')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['active', 'consumed', 'released'])->default('active');
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['sub_location_id', 'product_id', 'product_variation_id', 'status'], 'sub_stock_reservations_target_status');
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_stock_reservations');
    }
};
