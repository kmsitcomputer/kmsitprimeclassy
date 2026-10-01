<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package C / SC-03: idempotency for existing-order line addition.
 *
 * Adding a NEW product line to an existing order is an "append" operation — unlike a quantity
 * adjustment (which sets an absolute value and is therefore naturally idempotent), a retried
 * submission would otherwise create a second OrderItem with its own reservation, Shipment, Stock
 * Request line, commission and (when the order was fully paid) additional-payment obligation.
 *
 * The client's `Idempotency-Key` is persisted on the created line and uniquely constrained per
 * order, so a replay resolves to the already-created line and a conflicting reuse is rejected.
 *
 * Additive: historical rows and every checkout-created line stay NULL (MySQL/MariaDB permit many
 * NULLs in a UNIQUE index, so the constraint does not affect them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('split_from_order_item_id');
            $table->unique(['order_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
