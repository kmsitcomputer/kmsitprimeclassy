<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillment_change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('base_fulfilled_quantity');
            $table->unsignedInteger('proposed_fulfilled_quantity');
            $table->date('base_requested_delivery_date')->nullable();
            $table->date('proposed_requested_delivery_date')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['order_id', 'order_item_id', 'status'], 'ofcp_order_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_change_proposals');
    }
};