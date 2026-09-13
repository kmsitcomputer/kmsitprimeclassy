<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu order bisa beberapa kurir karena ada kemungkinan produk yang bisa di
 * reschedule" — a rescheduled item may need its own delivery batch/courier,
 * independent of the rest of the order. This drops the old 1:1
 * Order-to-Shipment constraint and gives every OrderItem its own shipment_id
 * (defaulting to the order's original shipment at creation time), so a
 * reschedule can later split an item off onto a brand new Shipment without
 * disturbing its siblings — see OrderFulfillmentService::rescheduleItemDeliveryDate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign('shipments_order_id_foreign');
            $table->dropUnique('shipments_order_id_unique');
            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('order_id')
                ->constrained('shipments')->nullOnDelete();
        });

        // Every existing item defaults to its order's single pre-existing shipment.
        DB::statement('
            UPDATE order_items oi
            INNER JOIN shipments s ON s.order_id = oi.order_id
            SET oi.shipment_id = s.id
            WHERE oi.shipment_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropIndex(['order_id']);
            $table->unique('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });
    }
};
