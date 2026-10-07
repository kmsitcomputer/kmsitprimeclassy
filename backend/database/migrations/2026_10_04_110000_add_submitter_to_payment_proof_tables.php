<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-001: assisted consumer payment. Records WHO submitted a payment proof (the payer actor)
 * separately from the order owner (orders.konsumen_id, never rewritten). Additive and nullable:
 * historical proofs keep NULL (submitter unknown) and are never guessed or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bank_transfer_verifications', 'cod_payment_proofs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('submitted_on_behalf')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['bank_transfer_verifications', 'cod_payment_proofs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('submitted_by_user_id');
                $table->dropColumn('submitted_on_behalf');
            });
        }
    }
};
