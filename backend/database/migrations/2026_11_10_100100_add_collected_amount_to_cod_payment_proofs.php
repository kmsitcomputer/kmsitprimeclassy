<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-004 — COD can be collected in several instalments. The nominal actually handed to the courier
 * is recorded with the proof; NULL = legacy "whole transaction amount" proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_payment_proofs', function (Blueprint $table) {
            $table->decimal('collected_amount', 14, 2)->nullable()->after('proof_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('cod_payment_proofs', function (Blueprint $table) {
            $table->dropColumn('collected_amount');
        });
    }
};
