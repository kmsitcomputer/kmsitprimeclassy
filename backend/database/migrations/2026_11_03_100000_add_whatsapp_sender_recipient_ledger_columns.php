<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUD-01–AUD-13 WS-02 — notification-ledger auditability (HA-01/HA-02, CL-01, CL-13). ADDITIVE ONLY: three nullable
 * columns on the existing `whatsapp_messages` ledger, no existing column touched, no data rewritten.
 *
 * - `template_class`  full | restricted — which template variant was rendered (CL-05 privacy matrix, CL-13 audit).
 * - `recipient_role`  admin | creator | recipient — HA-03 routing role of this row (existing buyer-only rows stay NULL
 *   and keep their behaviour; WS-03 fills the role for new rows).
 * - `sender_device_id` the GOWA device slot the message actually left through (HA-02/CL-01 explicit-sender evidence,
 *   CL-07 disconnect diagnostics). Operational, never a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('template_class', 20)->nullable()->after('event');
            $table->string('recipient_role', 20)->nullable()->after('template_class');
            $table->string('sender_device_id', 64)->nullable()->after('recipient_phone');
            $table->index(['recipient_role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['recipient_role', 'created_at']);
            $table->dropColumn(['template_class', 'recipient_role', 'sender_device_id']);
        });
    }
};
