<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-002 — dynamic invoice configuration.
 *
 * Admin-manageable invoice presentation config. Single global row per Agent
 * (agent_id unique = the agent's branch; a row with agent_id = 1..N belongs
 * to that agent; super_admin manages a global default row keyed by a
 * sentinel agent_id 0). The JSON `config` column holds structured display
 * toggles/strings (company identity, logo media id, title, contact, address,
 * footer, notes, visibilities) — never executable templates. The PDF builder
 * reads this through InvoiceConfigService, which applies per-agent -> global
 * -> built-in defaults resolution, mirroring the existing Google Auth config
 * resolution pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_configs', function (Blueprint $table) {
            $table->id();
            // agent_id semantics: 0 = global default (super_admin), >0 = that
            // agent's own row. Unique per scope.
            $table->unsignedBigInteger('agent_id')->default(0)->index();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_configs');
    }
};