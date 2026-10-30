<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-005 / WS-9 (CI-01): single-use OTP challenge bound to one subject konsumen, one purpose and
 * the capturing actor. Additive only; the plain code is never stored (HMAC only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 50);
            $table->string('code_hash', 64);
            $table->string('sent_to_email');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['subject_user_id', 'purpose', 'created_at'], 'otp_subject_purpose_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_otp_challenges');
    }
};
