<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_shipping_provider_configs', function (Blueprint $table) {
            $table->id();
            // Per-agen credentials (RajaOngkir api_key/account_type/origin_city_id/
            // couriers, or OpenRoute api_key) — replaces the old global
            // shipping_providers.config column.
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shipping_provider_id')->constrained('shipping_providers')->cascadeOnDelete();
            $table->text('config');
            $table->timestamps();

            $table->unique(['agent_id', 'shipping_provider_id'], 'agent_shipping_provider_configs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_shipping_provider_configs');
    }
};
