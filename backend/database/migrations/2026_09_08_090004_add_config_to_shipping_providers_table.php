<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_providers', function (Blueprint $table) {
            // Encrypted at rest (Laravel 'encrypted:array' cast, see
            // ShippingProvider model) — RajaOngkir's api_key/account_type/
            // origin_city_id/couriers, or OpenRoute's api_key. Never read back
            // to the frontend (see ShippingProviderController::present).
            $table->text('config')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_providers', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
