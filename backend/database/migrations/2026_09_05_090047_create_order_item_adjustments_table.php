<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-initiated reduction of an order item's quantity (e.g. stock shortfall),
        // distinct from a consumer-initiated return (see returns/return_items).
        Schema::create('order_item_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity_reduced');
            $table->string('reason', 255);
            $table->decimal('refund_amount', 12, 2)->unsigned()->default(0);
            $table->enum('refund_status', ['not_required', 'pending', 'processed', 'failed'])
                ->default('pending');
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_adjustments');
    }
};
