<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-001 Step 3 — Google Auth settings.
 *
 * Global (Super Admin) Google sign-in configuration. `settings`-style rows are
 * deliberately avoided for the secret: settings.value is longText and the
 * row is fetched with a plain `value` read, so an encrypted secret would be
 * leaking ciphertext into half the app's read paths. Instead this dedicated
 * table follows the existing per-agen credential pattern
 * (agent_payment_gateway_configs / agent_shipping_provider_configs): a
 * single-row table, `client_secret` encrypted at rest via the model, and
 * every read goes through the owning service. The non-secret fields stay
 * plain so they can be compared/returned directly.
 *
 * Global on/off is expressed as `is_enabled` (NULL = not configured at all);
 * it is never authoritative alone — GoogleAuthService still checks that a
 * usable client_id exists for the branch in question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_auth_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('client_id', 191)->nullable();
            $table->text('client_secret')->nullable(); // encrypted at rest (model cast)
            $table->string('redirect_uri', 255)->nullable();
            $table->string('frontend_url', 255)->nullable();
            $table->timestamps();
        });
        // The global settings row has a SINGLE, deterministic identity: id=1
        // is required by GoogleAuthSetting::SINGLE_ROW_ID. Seed it inside the
        // migration (plain, NULL secret) rather than relying on a database
        // trigger so `migrate:fresh`/rollback/refresh and test isolation all
        // reproduce the row at the same canonical id.
        DB::table('google_auth_settings')->insert([
            'id' => 1,
            'is_enabled' => false,
            'client_id' => null,
            'client_secret' => null,
            'redirect_uri' => null,
            'frontend_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Best-effort regeneration only when a canonical row already exists:
        // a database that ran the earlier (trigger-based) incarnation of this
        // migration may hold id=1 while a residual row was recreated at
        // id=2/… (RefreshDatabase between tests). Reassigning the oldest such
        // duplicate to id=1 restores the single-row invariant.
        $extra = DB::table('google_auth_settings')
            ->where('id', '!=', 1)
            ->orderBy('id')
            ->first();
        if ($extra !== null && DB::table('google_auth_settings')->whereKey(1)->exists()) {
            DB::table('google_auth_settings')->whereKey($extra->id)->update(['id' => 1]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_auth_settings');
    }
};