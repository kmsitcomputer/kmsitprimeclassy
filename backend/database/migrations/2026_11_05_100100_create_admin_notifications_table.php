<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notification centre for operators (Super Admin, Agen, Admin). ADDITIVE ONLY: one new table.
 *
 * One row per RECIPIENT user, so read state is personal and the list endpoint can only ever return the caller's own
 * rows (no cross-branch read by construction). `dedupe_key` is UNIQUE: a replayed fact never produces a second row.
 * Bodies are short operational summaries; they never contain credentials, OTPs, full phone numbers or bank details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 191)->unique();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 60);                           // order.created | delivery.failed | gateway.status ...
            $table->string('severity', 10)->default('info');      // info | warning | critical
            $table->string('title', 150);
            $table->string('body', 500);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'read_at', 'id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
