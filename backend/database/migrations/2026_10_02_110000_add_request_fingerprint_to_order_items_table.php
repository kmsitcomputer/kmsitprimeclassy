<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package C / SC-03 review remediation (C-SC03-REV-001): immutable request identity.
 *
 * Replay detection for an existing-order line addition used to compare the incoming request with the
 * CURRENT (mutable) OrderItem columns, so a legitimate retry broke after the line was adjusted or
 * split, and a request that differed in delivery date / payment method replayed as a success.
 *
 * `request_fingerprint` stores the SHA-256 of the ORIGINAL normalized logical request, written once
 * in the same transaction as the line and never updated. Additive and nullable: checkout lines and
 * any historical row stay NULL; no data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
