<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-003 — delivery REFUSAL (the customer declines goods at the door). Distinct from a post-delivery
 * customer return (return_items): the goods never arrived, so they stay in the courier's custody until
 * handed back to the Koordinator Kurir (two confirmations: courier hand-back, Koordinator receipt),
 * then a disposition: redelivery or return to Gudang. Proven gap: no existing table models custody
 * of undelivered goods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_refusals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 255);
            // with_courier → handed_back (courier confirms) → received (Koordinator confirms) → redelivery | returned_to_warehouse
            $table->string('status', 30)->default('with_courier');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('handed_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_back_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('disposition_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposition_at')->nullable();
            $table->string('disposition_note', 255)->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status']);
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_refusals');
    }
};
