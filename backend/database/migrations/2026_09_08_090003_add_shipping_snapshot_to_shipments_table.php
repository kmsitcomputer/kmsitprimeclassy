<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Snapshotted as plain text, not just via shipping_provider_id
            // (nullOnDelete) — a provider row being disabled/renamed/deleted
            // later must never change what a past shipment says was used.
            $table->string('shipping_provider_code', 30)->nullable()->after('shipping_provider_id');
            $table->decimal('rate_per_km', 10, 2)->nullable()->after('distance_km');
            // Raw provider response / calculation context for this specific
            // quote (RajaOngkir's chosen service+cost, or the OpenRoute
            // threshold rule that was applied) — audit/debugging only.
            $table->json('provider_meta')->nullable()->after('shipping_fee_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['shipping_provider_code', 'rate_per_km', 'provider_meta']);
        });
    }
};
