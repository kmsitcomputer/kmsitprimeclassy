<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * IMP-001 Step 3 — one Agen's own Google Auth configuration.
 *
 * `client_secret` is encrypted at rest (`encrypted` cast) and never echoed by
 * any API. Replaces the config atomically even when the previous ciphertext
 * was produced under an older APP_KEY (same APP_KEY-rotation caveat as
 * AgentPaymentGatewayConfig::replaceConfig) — updateOrCreate would try to
 * decrypt the old value just to determine dirty attributes.
 */
class AgentGoogleAuthConfig extends Model
{
    protected $fillable = [
        'agent_id',
        'is_enabled',
        'client_id',
        'client_secret',
        'redirect_uri',
        'frontend_url',
    ];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'client_secret' => 'encrypted',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Create or replace this agent's config row, never reading the previous
     * ciphertext (APP_KEY-rotation safe). When `$clientSecret` is null, the
     * previously stored secret is preserved unless `$clearSecret` is true.
     *
     * @return static
     */
    public static function replaceConfig(
        int $agentId,
        ?string $clientId,
        ?string $clientSecret,
        bool $isEnabled,
        ?string $redirectUri = null,
        ?string $frontendUrl = null,
        bool $clearSecret = false,
    ): self {
        $existingId = DB::table('agent_google_auth_configs')
            ->where('agent_id', $agentId)
            ->value('id');

        // Blank secret on edit keeps the old one (unless explicitly cleared):
        // we must not decrypt-and-compare, so read the old ciphertext raw.
        $preserved = null;
        if ($existingId && ($clientSecret === null || $clientSecret === '') && ! $clearSecret) {
            $preserved = DB::table('agent_google_auth_configs')->where('id', $existingId)->value('client_secret');
        }

        $attributes = [
            'is_enabled' => $isEnabled,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'frontend_url' => $frontendUrl,
        ];

        $model = new static;
        $model->setAttribute('client_secret', $clearSecret ? null : ($clientSecret ?: $preserved));

        if (! $existingId) {
            $attributes['agent_id'] = $agentId;
            $attributes['client_secret'] = $model->getAttributes()['client_secret'] ?? null;

            static::query()->create($attributes);

            return static::query()->where('agent_id', $agentId)->firstOrFail();
        }

        $attributes['client_secret'] = $model->getAttributes()['client_secret'] ?? null;
        DB::table('agent_google_auth_configs')->where('id', $existingId)->update([
            ...$attributes,
            'updated_at' => now(),
        ]);

        return static::query()->where('agent_id', $agentId)->firstOrFail();
    }
}