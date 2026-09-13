<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsumen_addresses', function (Blueprint $table) {
            $table->string('province_id', 2)->nullable()->after('address_line');
            $table->string('regency_id', 4)->nullable()->after('province_id');
            $table->string('district_id', 6)->nullable()->after('regency_id');
            $table->string('village_id', 10)->nullable()->after('district_id');

            $table->foreign('province_id')->references('id')->on('provinces')->nullOnDelete();
            $table->foreign('regency_id')->references('id')->on('regencies')->nullOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            $table->foreign('village_id')->references('id')->on('villages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('konsumen_addresses', function (Blueprint $table) {
            $table->dropForeign(['province_id']);
            $table->dropForeign(['regency_id']);
            $table->dropForeign(['district_id']);
            $table->dropForeign(['village_id']);
            $table->dropColumn(['province_id', 'regency_id', 'district_id', 'village_id']);
        });
    }
};
