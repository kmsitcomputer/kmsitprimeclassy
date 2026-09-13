<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_shipping_provider_settings', function (Blueprint $table) {
            $table->id();
            // Mirrors agent_payment_method_settings: an agen's own on/off
            // switch for rajaongkir/openroute, scoped to that agen's branch
            // only — separate from ShippingProvider's own super_admin-controlled
            // global `is_active`/credentials. No row = "not overridden".
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shipping_provider_id')->constrained('shipping_providers')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['agent_id', 'shipping_provider_id'], 'agent_shipping_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_shipping_provider_settings');
    }
};
