<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment audit: PAID BY (who supplied the money: konsumen|sales|korsal) is distinct from the proof
 * uploader (submitted_by_user_id) and the verifier (verified_by / confirmed_by). Additive and
 * nullable: historical rows keep NULL (unknown) and are never guessed or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bank_transfer_verifications', 'cod_payment_proofs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('paid_by_role', 20)->nullable();
                $table->timestamp('submitted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['bank_transfer_verifications', 'cod_payment_proofs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['paid_by_role', 'submitted_at']);
            });
        }
    }
};
