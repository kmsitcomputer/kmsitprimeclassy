<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03 / decision C: canonical structural lineage for a split order item.
 *
 * A partial split (delivery-date split or Sub quantity split) creates a brand new OrderItem. The
 * parent link is stored structurally here rather than only in ActivityLog (which stays as
 * supplemental audit), so lineage is queryable and reconcilable for both Agent and Sub splits.
 *
 * Additive: historical rows get NULL (they were never split).
 * RESTRICT ON DELETE: a parent line must never be hard-deleted out from under its splits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('split_from_order_item_id')->nullable()->after('shipment_id')
                ->constrained('order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['split_from_order_item_id']);
            $table->dropColumn('split_from_order_item_id');
        });
    }
};
