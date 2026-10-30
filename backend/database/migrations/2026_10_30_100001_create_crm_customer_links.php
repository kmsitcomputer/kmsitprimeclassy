<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS-9 / OD-03 / OD-04 / CI-05: CRM Customer <-> Konsumen account linking. Additive only.
 *  - `crm_customers.linked_konsumen_id` is the CURRENT state (unique => one CRM record per account,
 *    one account per CRM record; the DB prevents duplicates even under a race).
 *  - `crm_customer_link_events` is the append-only history (linked / unlinked).
 *  - `identity_otp_challenges.crm_customer_id` binds a link OTP to the intended CRM customer.
 * Linking never touches orders, payments, refunds, commissions or snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->foreignId('linked_konsumen_id')->nullable()->unique()
                ->constrained('users')->restrictOnDelete();
        });

        Schema::table('identity_otp_challenges', function (Blueprint $table) {
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customers')->restrictOnDelete();
        });

        Schema::create('crm_customer_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('crm_customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('konsumen_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('otp_challenge_id')->nullable()->unique()
                ->constrained('identity_otp_challenges')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['crm_customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customer_link_events');
        Schema::table('identity_otp_challenges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_customer_id');
        });
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_konsumen_id');
        });
    }
};
