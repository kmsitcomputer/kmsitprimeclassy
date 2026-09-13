<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Plain-text snapshots (not FKs) — deliberately independent of the
            // provinces/regencies/districts/villages rows, which Super Admin may
            // re-import/edit later. An order's shipping address must read
            // identically forever regardless of later reference-data changes.
            $table->string('province_snapshot', 100)->nullable()->after('address_snapshot');
            $table->string('regency_snapshot', 100)->nullable()->after('address_snapshot');
            $table->string('district_snapshot', 100)->nullable()->after('address_snapshot');
            $table->string('village_snapshot', 100)->nullable()->after('address_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['village_snapshot', 'district_snapshot', 'regency_snapshot', 'province_snapshot']);
        });
    }
};
