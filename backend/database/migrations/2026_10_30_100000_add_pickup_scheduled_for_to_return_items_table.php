<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-A (OD-P2-A, Owner 2026-10-30): return pickup scheduling.
 *
 * Additive + nullable: no backfill, no rewrite of historical returns. The date
 * is a WIB business-calendar DATE (same convention as visit/delivery dates).
 * Staff (Super Admin/Agen/Admin per the existing return-review authority) set
 * or change it; couriers see their own scheduled pickups. Audited through the
 * activity log, never destructive.
 *
 * EXECUTED ON DEV 2026-10-30 (Owner authorization of the same day; backup
 * `primeclassy_dev_before_phase2migrations_*.sql`, schema + data verified).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->date('pickup_scheduled_for')->nullable()->after('courier_id');
            $table->index('pickup_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropIndex(['pickup_scheduled_for']);
            $table->dropColumn('pickup_scheduled_for');
        });
    }
};