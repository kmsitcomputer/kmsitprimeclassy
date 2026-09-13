<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->enum('type', ['payment', 'refund', 'additional_payment']);
            $table->decimal('amount', 12, 2)->unsigned();
            $table->enum('status', [
                'pending', 'processing', 'success', 'failed', 'expired', 'cancelled',
            ])->default('pending');
            $table->string('gateway_reference', 100)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
            $table->unique(['payment_method_id', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
