<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('beneficiary_role', ['agent', 'sales']);
            // Signed: reversal entries (from returns) are negative rows, so SUM(amount)
            // gives the net payable commission directly for reporting.
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'approved', 'paid', 'reversed'])->default('pending');
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['beneficiary_user_id', 'status']);
            $table->index('order_id');
            $table->index('earned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
