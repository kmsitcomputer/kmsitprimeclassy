<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS-8 (Owner 2026-10-30): refund-driven Sales-fee cancellation.
 *
 * Additive + nullable: `commission_adjustments` gains `return_item_id` so every
 * fee-cancel entry is traceable to the EXACT processed return line that caused
 * it, and the unique (commission_id, return_item_id) pair makes a duplicate —
 * a retried refund settlement, an observer replay, anything — impossible at
 * the database level. Returns are never deleted (RESTRICT), historical data is
 * never touched, and existing NULL-bearing rows are unaffected by the unique
 * index (NULL != NULL in MySQL).
 *
 * EXECUTED ON DEV 2026-10-30 (Owner authorization of the same day; backup
 * `primeclassy_dev_before_phase2migrations_*.sql`, schema + data verified;
 * pre-migration `commission_adjustments` row count = 0 so the unique index
 * could not collide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_adjustments', function (Blueprint $table) {
            $table->foreignId('return_item_id')->nullable()
                ->constrained('return_items')->restrictOnDelete()
                ->after('refund_obligation_id');
            $table->unique(['commission_id', 'return_item_id'], 'commission_adjustments_commission_return_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commission_adjustments', function (Blueprint $table) {
            $table->dropUnique('commission_adjustments_commission_return_unique');
            $table->dropConstrainedForeignId('return_item_id');
        });
    }
};
