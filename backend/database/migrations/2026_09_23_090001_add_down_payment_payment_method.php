<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the DOWN PAYMENT / DP payment method — a proof-based (manual)
 * partial payment: the konsumen transfers a nominal DP and uploads the proof,
 * Keuangan verifies it, and the remaining balance stays outstanding until a
 * later settlement. Idempotent and safe on an already-populated database.
 *
 * DP reuses the branch's existing bank_transfer destination account (see
 * PaymentService::initiateDownPayment) — there is no separate bank config to
 * enter, so an agen that already accepts manual transfers accepts DP too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_methods')->updateOrInsert(
            ['code' => 'down_payment'],
            [
                'name' => 'DP / Down Payment',
                'type' => 'manual',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $id = DB::table('payment_methods')->where('code', 'down_payment')->value('id');

        if (! $id) {
            return;
        }

        // Never remove a method that already has transactions referencing it
        // (the FK is restrictOnDelete and would throw anyway).
        if (DB::table('payment_transactions')->where('payment_method_id', $id)->exists()) {
            return;
        }

        DB::table('payment_methods')->where('id', $id)->delete();
    }
};
