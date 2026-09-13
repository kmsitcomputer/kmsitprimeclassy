<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indonesia's 4-level administrative hierarchy (Provinsi -> Kota/Kabupaten
 * -> Kecamatan -> Kelurahan/Desa) as reference data — codes are the
 * conventional string codes (e.g. "32", "3273", "327301", "3273011001"), not
 * auto-increment ids, so the same CSV export/import round-trips identically
 * regardless of which database it's loaded into (Blueprint: "pastikan hasil
 * export tersebut terinput ke database"). No rows are seeded here — this
 * project ships no fabricated government data; Super Admin populates these
 * via the CSV import (see RegionImportExportController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->string('id', 2)->primary();
            $table->string('name', 100);
        });

        Schema::create('regencies', function (Blueprint $table) {
            $table->string('id', 4)->primary();
            $table->string('province_id', 2);
            $table->string('name', 100);
            // RajaOngkir has its own, unrelated city-id numbering — nullable
            // mapping so a RajaOngkir cost lookup can resolve a destination
            // without requiring every regency to have one populated.
            $table->string('rajaongkir_city_id', 10)->nullable();

            $table->foreign('province_id')->references('id')->on('provinces')->cascadeOnDelete();
            $table->index('province_id');
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->string('id', 6)->primary();
            $table->string('regency_id', 4);
            $table->string('name', 100);

            $table->foreign('regency_id')->references('id')->on('regencies')->cascadeOnDelete();
            $table->index('regency_id');
        });

        Schema::create('villages', function (Blueprint $table) {
            $table->string('id', 10)->primary();
            $table->string('district_id', 6);
            $table->string('name', 100);

            $table->foreign('district_id')->references('id')->on('districts')->cascadeOnDelete();
            $table->index('district_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regencies');
        Schema::dropIfExists('provinces');
    }
};
