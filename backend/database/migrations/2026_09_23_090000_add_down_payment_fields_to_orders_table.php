<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Down Payment (DP) accounting on the order itself.
 *
 * A DP order is never fully paid at checkout — it carries an explicit
 * dp_amount (the partial amount the konsumen transfers first), a running
 * paid_amount, and the remaining_amount still owed. A new 'partially_paid'
 * payment status represents exactly that state, so DP is never confused with
 * PAID/LUNAS (Blueprint requirement).
 *
 * Purely additive and safe on live data: existing orders are backfilled
 * (already-paid => paid_amount = total, everything else => whole total
 * outstanding) so nothing changes meaning for historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('dp_amount', 12, 2)->default(0)->after('admin_fee_amount');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('dp_amount');
            $table->decimal('remaining_amount', 12, 2)->default(0)->after('paid_amount');
        });

        // Widen the payment lifecycle with 'partially_paid'. MySQL requires the
        // full value list on MODIFY; order matches the original definition with
        // the new value inserted after 'pending_verification'.
        DB::statement(
            "ALTER TABLE orders MODIFY payment_status "
            ."ENUM('unpaid','pending_verification','partially_paid','paid','partially_refunded','refunded','failed') "
            ."NOT NULL DEFAULT 'unpaid'"
        );

        // Backfill existing rows.
        DB::statement("UPDATE orders SET paid_amount = total_amount, remaining_amount = 0 WHERE payment_status = 'paid'");
        DB::statement("UPDATE orders SET remaining_amount = total_amount WHERE payment_status <> 'paid'");
    }

    public function down(): void
    {
        // 'partially_paid' cannot exist in the narrowed enum — fold it back to
        // 'unpaid' (the order still owes money, which is what unpaid means).
        DB::statement("UPDATE orders SET payment_status = 'unpaid' WHERE payment_status = 'partially_paid'");

        DB::statement(
            "ALTER TABLE orders MODIFY payment_status "
            ."ENUM('unpaid','pending_verification','paid','partially_refunded','refunded','failed') "
            ."NOT NULL DEFAULT 'unpaid'"
        );

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['dp_amount', 'paid_amount', 'remaining_amount']);
        });
    }
};
