<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payment_method_settings', function (Blueprint $table) {
            $table->id();
            // An agen's own on/off switch for a cod/manual payment method,
            // scoped to that agen's branch only — separate from PaymentMethod's
            // own `is_active` (the super_admin-controlled global switch). No
            // row here for a given (agent_id, payment_method_id) pair means
            // "not overridden" — the global is_active value applies.
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['agent_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payment_method_settings');
    }
};
