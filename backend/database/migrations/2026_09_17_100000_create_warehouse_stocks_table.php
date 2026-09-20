<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->string('stock_type', 30);
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'product_id', 'product_variation_id', 'stock_type'], 'warehouse_stocks_unique_target');
            $table->index(['agent_id', 'stock_type', 'product_id', 'product_variation_id'], 'warehouse_stocks_agent_type_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
