<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_initial_report_items', function (Blueprint $table) {
            $table->id();

            // The snapshot is derived from the order: it has no meaning without
            // it, so it dies with its order (same rule as order_items.order_id).
            // Production never deletes an order, so no historical report row can
            // be lost there; the DEV cleanup tools stay usable.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();

            $table->string('order_no', 60);
            $table->dateTime('order_created_at');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('korsal_id')->nullable();
            $table->unsignedBigInteger('sales_id')->nullable();
            $table->unsignedBigInteger('konsumen_id')->nullable();

            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variation_id')->nullable();
            $table->string('sku_snapshot')->nullable();
            $table->string('product_name_snapshot');
            $table->string('variation_label_snapshot')->nullable();
            $table->string('category_name_snapshot')->nullable();

            $table->unsignedInteger('initial_quantity');
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('item_subtotal_snapshot', 15, 2);

            $table->string('recipient_name_snapshot')->nullable();
            $table->string('recipient_phone_snapshot', 50)->nullable();
            $table->text('address_snapshot')->nullable();
            $table->string('village_snapshot')->nullable();
            $table->string('district_snapshot')->nullable();
            $table->string('regency_snapshot')->nullable();
            $table->string('province_snapshot')->nullable();

            $table->decimal('invoice_subtotal_snapshot', 15, 2);
            $table->decimal('invoice_discount_snapshot', 15, 2);
            $table->decimal('invoice_shipping_snapshot', 15, 2);
            $table->decimal('invoice_admin_fee_snapshot', 15, 2);
            $table->decimal('invoice_total_snapshot', 15, 2);
            $table->decimal('initial_paid_snapshot', 15, 2);
            $table->decimal('initial_remaining_snapshot', 15, 2);
            $table->string('initial_payment_status', 40);

            $table->unsignedInteger('item_position');
            $table->timestamps();

            $table->index(['agent_id', 'order_created_at']);
            $table->index(['order_id', 'item_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_initial_report_items');
    }
};
