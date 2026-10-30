<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-005 / WS-9 (CI-02/03/04): append-only consent event stream. Withdrawal, dispute and
 * reinstatement are further rows pointing at the root `consent_captured` row — never updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('return_id')->nullable()->constrained('returns')->restrictOnDelete();
            $table->foreignId('root_event_id')->nullable()->constrained('consent_events')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('purpose', 50);
            $table->json('scope')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('otp_challenge_id')->nullable()->constrained('identity_otp_challenges')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('consent_key', 100)->nullable()->unique();
            $table->string('policy_version', 30);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_user_id', 'order_id', 'purpose'], 'consent_subject_order_purpose_idx');
            $table->index('root_event_id');
            // A challenge can back at most one captured consent (replay protection).
            $table->unique('otp_challenge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
    }
};
