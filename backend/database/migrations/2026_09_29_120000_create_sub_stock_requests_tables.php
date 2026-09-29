<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-02: Sales-Kurir-Sub stock requests.
 *
 *   replenish (Transit -> Sub): requested -> approved (Admin) -> executed (Gudang moves stock, movements + handover) -> received (Sub)
 *   return    (Sub -> Transit): requested -> approved (Admin) -> executed (Gudang receives goods, movements + handover)
 *   requested may also end as rejected (Admin) or cancelled (requester). NO stock changes before 'executed'.
 *
 * The physical move itself reuses the audited stock_transfers / stock_movements / stock_handovers
 * machinery (stock_transfer_id links the request to its movements). The unique
 * (requested_by, idempotency_key) index makes a retried create a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_stock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sub_location_id')->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->string('request_number', 40)->unique();
            $table->enum('direction', ['replenish', 'return']);
            $table->enum('status', ['requested', 'approved', 'rejected', 'cancelled', 'executed', 'received'])->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('stock_transfer_id')->nullable()->unique()->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'idempotency_key'], 'sub_stock_requests_idempotency_unique');
            $table->index(['agent_id', 'status']);
            $table->index(['sub_location_id', 'status']);
        });

        Schema::create('sub_stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_stock_request_id')->constrained('sub_stock_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_stock_request_items');
        Schema::dropIfExists('sub_stock_requests');
    }
};
