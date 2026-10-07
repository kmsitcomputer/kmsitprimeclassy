<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-001: external identity link (Google). Pure authentication data — it never carries or
 * changes referral/ownership fields, and no OAuth tokens are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // One external identity can never belong to two users; one user has at most one per provider.
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_identities');
    }
};
