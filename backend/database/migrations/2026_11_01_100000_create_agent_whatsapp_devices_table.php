<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — WhatsApp per Agen (Owner directive 2026-10-29). ADDITIVE only: one new table, nothing existing is altered.
 *
 * ONE central GOWA server; each Agent owns exactly ONE active device slot on it (`agent_id` is UNIQUE = "one active device per
 * Agen"). `device_id` is the id of the slot on GOWA and is also UNIQUE, so two Agents can never share a session. A controlled
 * device replacement rewrites this row (new `device_id`, `replaced_count`+1, audit in activity_logs); it never adds a second row.
 * No GOWA credential and no full WhatsApp number is stored here (only a masked number for display).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_whatsapp_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('device_id', 64)->unique();
            // not_paired | pairing | connected | disconnected  (cache of the last observed gateway state)
            $table->string('state', 20)->default('not_paired');
            $table->string('phone_masked', 40)->nullable();
            // Set the first time the slot was observed logged in: a never-paired slot is never used for sending.
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('replaced_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_whatsapp_devices');
    }
};
