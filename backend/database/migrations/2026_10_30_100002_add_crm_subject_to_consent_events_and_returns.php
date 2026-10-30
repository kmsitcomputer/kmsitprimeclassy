<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CI-02/CI-03 for CRM-origin orders. A CRM consent is OTP-verified on the linked konsumen account's verified
 * channel (Owner 2026-10-31), so a consent row records BOTH the verified identity (`subject_user_id`, unchanged and
 * still NOT NULL) and the CRM customer it concerns (`crm_customer_id`, new, nullable). `returns.consent_event_id`
 * records which consent authorized an assisted return initiation; NULL for every existing/account return.
 * Purely additive: no existing column is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_events', function (Blueprint $table) {
            $table->foreignId('crm_customer_id')->nullable()->after('subject_user_id')
                ->constrained('crm_customers')->restrictOnDelete();
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('consent_event_id')->nullable()->constrained('consent_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consent_event_id');
        });
        Schema::table('consent_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_customer_id');
        });
    }
};
