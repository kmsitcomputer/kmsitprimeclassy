<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()
                ->constrained('product_variations')->restrictOnDelete();
            $table->foreignId('additional_payment_id')->nullable()
                ->constrained('order_additional_payments')->nullOnDelete();

            // Snapshots — never re-joined live to catalog data.
            $table->string('product_name_snapshot', 150);
            $table->string('variation_label_snapshot', 150)->nullable();
            $table->string('sku_snapshot', 60)->nullable();
            $table->decimal('unit_price_snapshot', 12, 2)->unsigned();
            $table->decimal('agent_fee_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('sales_fee_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('subtotal_snapshot', 12, 2)->unsigned();

            $table->unsignedInteger('original_quantity');
            $table->unsignedInteger('fulfilled_quantity');
            $table->enum('status', [
                'pending', 'processing', 'fulfilled', 'partially_returned', 'returned', 'cancelled',
            ])->default('pending');

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
            $table->index('product_variation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
