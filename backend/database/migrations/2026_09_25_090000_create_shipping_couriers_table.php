<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master registry of couriers PROVIDER-SUPPORTED by the RajaOngkir/Komerce
 * Shipping Cost API V2 integration (see RajaOngkirProvider) — Super Admin
 * managed, seeded from researched documentation (see ShippingCourierSeeder),
 * never hardcoded into business logic. This is the "SUPPORTED" half of
 * "AVAILABLE = SUPPORTED ∩ AGENT ENABLED" — the agent-enabled half already
 * lives in agent_shipping_provider_configs.config->couriers (reused as-is,
 * no new per-agent table needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_couriers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // Whether Super Admin currently considers this courier valid to
            // offer/enable at all — turning this off retroactively excludes
            // it from every agent's AVAILABLE set (see the intersection in
            // RajaOngkirProvider), without touching any agent's own saved
            // enabled list.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_couriers');
    }
};
