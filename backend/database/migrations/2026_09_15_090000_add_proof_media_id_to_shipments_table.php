<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kurir harus memasukan bukti pengiriman jika ingin merubah status kirim
 * dari dikirim jadi terkirim" — enforced in CourierService::updateShipmentStatus,
 * never just a frontend prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('proof_media_id')->nullable()->after('tracking_number')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proof_media_id');
        });
    }
};
