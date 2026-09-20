<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index('stock_transfer_id');
            $table->index(['product_id', 'product_variation_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_stock_transfer_items_target CHECK ((product_id IS NOT NULL AND product_variation_id IS NULL) OR (product_id IS NULL AND product_variation_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
