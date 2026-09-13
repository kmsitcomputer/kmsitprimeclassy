<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshotted at order-creation time exactly like agent_fee_amount/
            // sales_fee_amount — never re-derived, so a later fee config change
            // never touches a past order. The Commission row for this fee is
            // only created once a courier actually completes delivery (see
            // OrderFulfillmentService/CourierController) — unlike agent/sales
            // fees, which are earned regardless of delivery outcome.
            $table->decimal('courier_fee_amount', 12, 2)->unsigned()->default(0)->after('sales_fee_amount');

            // Per-product delivery schedule — "setiap produk dalam pesanan
            // dapat dirubah tanggal kirimnya", editable by admin/agen/super_admin
            // only while the item hasn't shipped yet. Surfaces on the courier
            // dashboard's delivery queue.
            $table->date('requested_delivery_date')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['courier_fee_amount', 'requested_delivery_date']);
        });
    }
};
