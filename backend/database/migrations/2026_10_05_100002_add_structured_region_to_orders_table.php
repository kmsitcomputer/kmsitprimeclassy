<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-002 — structured checkout address persistence on orders.
 *
 * The existing Order already snapshots recipient/address line + village/
 * district/regency/province NAMES + lat/lng. IMP-002 adds the canonical
 * stable region IDs (province_id/regency_id/district_id/village_id + postal
 * code) so orders can be filtered/grouped by canonical region in Dispatch
 * (IMP-003) and rendered on the dynamic invoice, while the original name
 * snapshots remain untouched for historical compatibility.
 *
 * All nullable, additive-only. Existing rows keep NULL (no backfill attempt;
 * the ID hierarchy is not derivable from a name snapshot reliably).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Region ids in the canonical regional master are STRING ids with
        // fixed widths (province 2 / regency 4 / district 6 / village 10) —
        // orders columns mirror those exact widths so they can hold the
        // canonical ids (no FK constraint; region master may be re-imported).
        Schema::table('orders', function (Blueprint $table) {
            $table->string('province_id', 2)->nullable()->index();
            $table->string('regency_id', 4)->nullable()->index();
            $table->string('district_id', 6)->nullable()->index();
            $table->string('village_id', 10)->nullable()->index();
            $table->string('postal_code', 10)->nullable();
        });

        // Order-level voucher reference: which voucher (if any) was applied
        // to this order. The discount amount is already snapshotted on
        // order.discount_amount (historical truth, never recalculated); this
        // column is a read-only attribution for reporting/UI.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['province_id', 'regency_id', 'district_id', 'village_id', 'postal_code', 'voucher_id']);
        });
    }
};