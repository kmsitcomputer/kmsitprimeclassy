<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cancellation_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('stock_request_id')->nullable()->constrained('stock_requests')->nullOnDelete();
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('released_quantity')->default(0);
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('order_item_id');
            $table->index(['agent_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cancellation_reversals');
    }
};
