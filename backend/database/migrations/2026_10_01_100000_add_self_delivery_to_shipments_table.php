<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-03 / decision A: first-class Sales-Kurir-Sub self-delivery.
 *
 * A Sub-sourced order is shipped by the owning Sales-Kurir-Sub, who has NO Courier profile — so
 * `courier_id` (which points at the `couriers` table and stays reserved for normal Kurir) must not
 * be used to represent them. `self_delivered_by_user_id` is the stable `users` reference to the
 * actual self-delivery actor, and `delivery_mode` marks the shipment as routed through that path.
 *
 * RESTRICT ON DELETE (not nullOnDelete): Users are soft-deleted, so a hard delete must never be
 * allowed to silently erase the audit identity of who delivered the goods.
 *
 * Consistency invariant (mirrors the locked architecture):
 *   (delivery_mode = 'standard' AND self_delivered_by_user_id IS NULL)
 *   OR (delivery_mode = 'self_sub' AND self_delivered_by_user_id IS NOT NULL AND courier_id IS NULL)
 *
 * MariaDB 10.11 cannot reference a foreign-key column whose FK action is SET NULL (`courier_id` is
 * `nullOnDelete`) inside a CHECK clause (error 1901). The two-way mode/actor half is therefore a
 * real CHECK constraint, and the "self_sub never carries a courier" half is enforced with BEFORE
 * INSERT / BEFORE UPDATE triggers — the exact same predicate, without altering existing FKs.
 *
 * Additive: historical rows keep `delivery_mode='standard'` (default) and a NULL
 * `self_delivered_by_user_id`, which is exactly what the constraint requires of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->enum('delivery_mode', ['standard', 'self_sub'])->default('standard')->after('status');
            $table->foreignId('self_delivered_by_user_id')->nullable()->after('delivery_mode')
                ->constrained('users')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE shipments ADD CONSTRAINT shipments_delivery_mode_consistent CHECK (
                    (delivery_mode = 'standard' AND self_delivered_by_user_id IS NULL)
                    OR (delivery_mode = 'self_sub' AND self_delivered_by_user_id IS NOT NULL)
                )"
            );

            DB::unprepared(
                "CREATE TRIGGER shipments_self_sub_no_courier_insert BEFORE INSERT ON shipments
                 FOR EACH ROW
                 BEGIN
                   IF NEW.delivery_mode = 'self_sub' AND NEW.courier_id IS NOT NULL THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'self_sub shipment must not carry a courier_id';
                   END IF;
                 END"
            );

            DB::unprepared(
                "CREATE TRIGGER shipments_self_sub_no_courier_update BEFORE UPDATE ON shipments
                 FOR EACH ROW
                 BEGIN
                   IF NEW.delivery_mode = 'self_sub' AND NEW.courier_id IS NOT NULL THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'self_sub shipment must not carry a courier_id';
                   END IF;
                 END"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS shipments_self_sub_no_courier_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS shipments_self_sub_no_courier_update');
            DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_delivery_mode_consistent');
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['self_delivered_by_user_id']);
            $table->dropColumn(['delivery_mode', 'self_delivered_by_user_id']);
        });
    }
};
