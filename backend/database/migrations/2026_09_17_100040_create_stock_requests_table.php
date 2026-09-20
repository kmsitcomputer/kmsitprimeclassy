<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('request_number', 40)->unique();
            $table->enum('status', ['pending', 'partial', 'fulfilled', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status']);
        });

        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->string('sku_snapshot', 100)->nullable();
            $table->unsignedInteger('requested_qty');
            $table->unsignedInteger('fulfilled_qty')->default(0);
            $table->unsignedInteger('remaining_qty');
            $table->timestamps();
            $table->index('stock_request_id');
        });

        Schema::create('stock_request_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->foreignId('fulfilled_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['stock_request_id', 'idempotency_key'], 'sr_fulfillment_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_fulfillments');
        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('stock_requests');
    }
};
