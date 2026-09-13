<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_configurations', function (Blueprint $table) {
            $table->id();
            // NULL agent_id = the global default configuration row.
            $table->foreignId('agent_id')->nullable()->unique()
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('shipping_provider_id')->nullable()
                ->constrained('shipping_providers')->nullOnDelete();
            $table->decimal('price_per_km', 10, 2)->unsigned();
            $table->decimal('minimum_distance_km', 6, 2)->unsigned()->default(0);
            $table->decimal('minimum_charge', 12, 2)->unsigned()->default(0);
            $table->boolean('free_shipping_enabled')->default(false);
            $table->decimal('free_shipping_min_amount', 12, 2)->unsigned()->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_configurations');
    }
};
