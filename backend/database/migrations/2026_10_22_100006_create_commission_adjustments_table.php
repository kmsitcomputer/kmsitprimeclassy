<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed commission-reversal ledger (D1: TABLE ONLY).
 *
 * The generation logic is deliberately NOT implemented: the Owner approved preserving existing
 * commission rules and activating no refund commission adjustment until a calculation/rounding
 * policy is separately approved (14 §2 D1). Original commission rows stay immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('commissions')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('beneficiary_role', ['agent', 'sales']);
            // Signed on purpose: reversals are negative, so SUM(amount) is the net adjustment.
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('refund_obligation_id')->nullable()
                ->constrained('refund_obligations')->nullOnDelete();
            $table->string('policy_version', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'beneficiary_user_id']);
            $table->index('commission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
    }
};
