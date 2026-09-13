<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            // Per-agen credentials (bank_transfer account details, or
            // Xendit/Tripay/Stripe API keys) — replaces the old global
            // payment_gateway_configs table. Never resolved without an
            // explicit agent_id; super_admin no longer has a config surface.
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->enum('environment', ['sandbox', 'production']);
            $table->text('config');
            $table->timestamps();

            $table->unique(['agent_id', 'payment_method_id', 'environment'], 'agent_payment_gateway_configs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payment_gateway_configs');
    }
};
