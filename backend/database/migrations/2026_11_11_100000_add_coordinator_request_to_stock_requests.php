<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-001 — the Koordinator Kurir's explicit "request fulfilment from Gudang" is recorded on the
 * order's ONE existing Stock Request (never a second request): latest requester + time + note.
 * Additive and nullable; history of every request lives in activity_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->foreignId('coordinator_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('coordinator_requested_at')->nullable();
            $table->string('coordinator_request_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coordinator_requested_by');
            $table->dropColumn(['coordinator_requested_at', 'coordinator_request_note']);
        });
    }
};
