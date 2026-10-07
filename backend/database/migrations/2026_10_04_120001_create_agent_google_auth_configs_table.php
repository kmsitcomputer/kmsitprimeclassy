<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-001 Step 3 — Google Auth per-Agen configuration.
 *
 * Agent-specific Google credentials/configuration, scoped to exactly one
 * agent row (isolation: the controller always writes with
 * agent_id = auth user's own agent_id, never from input — same pattern as
 * AgentPaymentMethodController). `client_secret` is encrypted at rest via
 * the model cast; none of it is ever echoed back through any API — the
 * frontend only learns `configured: bool`.
 *
 * `agent_id` alone is the scope key (an agen's own agent_id equals their own
 * id; an admin of the branch resolves to the same branch — see
 * AgentPaymentMethodController's docblock for the identical convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_google_auth_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('client_id', 191)->nullable();
            $table->text('client_secret')->nullable(); // encrypted at rest (model cast)
            $table->string('redirect_uri', 255)->nullable();
            $table->string('frontend_url', 255)->nullable();
            $table->timestamps();

            $table->unique('agent_id', 'agent_google_auth_configs_agent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_google_auth_configs');
    }
};