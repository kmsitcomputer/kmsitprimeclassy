<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package C / production UAT remediation: per-product (proposal-item) Admin decision.
 *
 * A fulfilment proposal is Gudang's PROPOSED fulfilment of order demand, one line per product. The
 * Admin used to approve/reject the whole proposal, so one click executed every product. The decision
 * now lives on the proposal ITEM (`decision_status`), never on `stock_request_items` — rejecting a
 * proposed fulfilment must not cancel the underlying order demand.
 *
 * Additive: new columns default to `pending`; the proposal header enum gains `partial` (derived:
 * items decided non-uniformly). Backfill uses ONLY existing header evidence (an approved/rejected
 * proposal already recorded who/when) — pending proposals stay pending, nothing is guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_proposal_items', function (Blueprint $table) {
            $table->enum('decision_status', ['pending', 'approved', 'rejected'])->default('pending')->after('quantity');
            $table->foreignId('decided_by')->nullable()->after('decision_status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_reason', 255)->nullable()->after('decided_at');
            $table->index(['stock_request_proposal_id', 'decision_status'], 'srpi_proposal_decision_idx');
        });

        DB::statement("ALTER TABLE stock_request_proposals MODIFY status ENUM('pending','partial','approved','rejected') NOT NULL DEFAULT 'pending'");

        // Backfill from the header's own recorded evidence (idempotent: only still-pending items).
        DB::statement("UPDATE stock_request_proposal_items i JOIN stock_request_proposals p ON p.id = i.stock_request_proposal_id
            SET i.decision_status = 'approved', i.decided_by = p.approved_by, i.decided_at = p.approved_at
            WHERE p.status = 'approved' AND i.decision_status = 'pending'");
        DB::statement("UPDATE stock_request_proposal_items i JOIN stock_request_proposals p ON p.id = i.stock_request_proposal_id
            SET i.decision_status = 'rejected', i.decided_by = p.rejected_by, i.decided_at = p.rejected_at, i.decision_reason = p.rejection_reason
            WHERE p.status = 'rejected' AND i.decision_status = 'pending'");
    }

    public function down(): void
    {
        DB::table('stock_request_proposals')->where('status', 'partial')->update(['status' => 'pending']);
        DB::statement("ALTER TABLE stock_request_proposals MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('stock_request_proposal_items', function (Blueprint $table) {
            $table->dropIndex('srpi_proposal_decision_idx');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decision_status', 'decided_at', 'decision_reason']);
        });
    }
};
