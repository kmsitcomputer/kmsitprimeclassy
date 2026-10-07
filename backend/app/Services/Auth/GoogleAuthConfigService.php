<?php

namespace App\Services\Auth;

use App\Models\AgentGoogleAuthConfig;
use App\Models\GoogleAuthSetting;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * IMP-001 Step 3 — the single owner of Google Auth configuration reads and
 * writes. The dashboard (Super Admin global, Agen per-branch) is the normal
 * management path; .env remains only a bootstrap/fallback (see
 * GoogleAuthService::isConfigured for the exact precedence).
 *
 * Security invariants enforced here:
 *  - client_secret is encrypted at rest and never returned by any API — the
 *    most a controller ever learns is `has_secret: bool` (and nothing else
 *    is secret, so client_id/redirect_uri/frontend_url are plain).
 *  - writes keep the existing secret when the edit sends a blank one; only
 *    an explicit clear (or a fresh non-blank value) replaces it.
 *  - every write is audited (ActivityLogger) with field NAMES and a
 *    `secret_changed` flag — never the secret itself (ActivityLogger's own
 *    redaction is a second layer).
 */
class GoogleAuthConfigService
{
    /**
     * Effective global configuration for a branch. Resolution order:
     *  1. the Agen's own row (AgentGoogleAuthConfig) when present;
     *  2. the global row (GoogleAuthSetting) when present;
     *  3. .env (`services.google.*`), which also serves as bootstrap/fallback
     *     before anyone has saved settings in the dashboard.
     *
     * @return array{is_enabled:bool, client_id:?string, client_secret:?string,
     *               redirect_uri:?string, frontend_url:?string, source:string}
     */
    public function resolvedForAgent(?int $agentId): array
    {
        if ($agentId !== null) {
            $agent = AgentGoogleAuthConfig::query()->where('agent_id', $agentId)->first();
            if ($agent && filled($agent->client_id)) {
                return $this->present($agent, 'agent');
            }
        }

        $global = GoogleAuthSetting::query()->whereKey(GoogleAuthSetting::SINGLE_ROW_ID)->first();
        if ($global && filled($global->client_id)) {
            return $this->present($global, 'global');
        }

        return [
            'is_enabled' => filled(config('services.google.client_id')),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'frontend_url' => config('services.google.frontend_url', ''),
            'source' => 'env',
        ];
    }

    /** @return array{is_enabled:bool, client_id:?string, redirect_uri:?string, frontend_url:?string, has_secret:bool} */
    public function forSuperAdmin(): array
    {
        $row = GoogleAuthSetting::query()->whereKey(GoogleAuthSetting::SINGLE_ROW_ID)->first();

        return [
            'is_enabled' => $row?->is_enabled ?? false,
            'client_id' => $row?->client_id,
            'redirect_uri' => $row?->redirect_uri,
            'frontend_url' => $row?->frontend_url,
            'has_secret' => $row !== null && filled($row->client_secret),
        ];
    }

    /** @return array{is_enabled:bool, client_id:?string, redirect_uri:?string, frontend_url:?string, has_secret:bool} */
    public function forAgent(int $agentId): array
    {
        $row = AgentGoogleAuthConfig::query()->where('agent_id', $agentId)->first();

        return [
            'is_enabled' => $row?->is_enabled ?? false,
            'client_id' => $row?->client_id,
            'redirect_uri' => $row?->redirect_uri,
            'frontend_url' => $row?->frontend_url,
            'has_secret' => $row !== null && filled($row->client_secret),
        ];
    }

    public function updateGlobal(User $actor, array $data, bool $isEnabled): array
    {
        return $this->write(
            $actor,
            GoogleAuthSetting::SINGLE_ROW_ID,
            GoogleAuthSetting::class,
            $data,
            $isEnabled,
            'google_auth.settings_updated',
            null,
        );
    }

    public function updateForAgent(User $actor, int $agentId, array $data, bool $isEnabled): array
    {
        return $this->write(
            $actor,
            $agentId,
            AgentGoogleAuthConfig::class,
            $data,
            $isEnabled,
            'google_auth.agent_config_updated',
            $agentId,
        );
    }

    private function write(
        User $actor,
        int $scopeKey,
        string $modelClass,
        array $data,
        bool $isEnabled,
        string $event,
        ?int $logAgentId,
    ): array {
        $before = $this->presentRow($scopeKey, $modelClass);

        $secretChanged = false;
        $clearSecret = (bool) ($data['clear_secret'] ?? false);

        if ($modelClass === GoogleAuthSetting::class) {
            $row = GoogleAuthSetting::singleton();
            $secretChanged = $this->applySecretChange($row, $data['client_secret'] ?? null, $clearSecret);
            $row->forceFill([
                'is_enabled' => $isEnabled,
                'client_id' => $this->normalize($data['client_id'] ?? null),
                'redirect_uri' => $this->normalize($data['redirect_uri'] ?? null),
                'frontend_url' => $this->normalize($data['frontend_url'] ?? null),
            ])->save();
        } else {
            /** @var AgentGoogleAuthConfig $row */
            $row = AgentGoogleAuthConfig::query()->where('agent_id', $scopeKey)->first();
            if ($row === null) {
                $row = new AgentGoogleAuthConfig;
                $row->agent_id = $scopeKey;
            }

            $existingSecretPlain = $row->client_secret; // null when new
            $secretChanged = $this->applySecretChangeToModel(
                $row,
                $data['client_secret'] ?? null,
                $clearSecret,
                $existingSecretPlain,
            );

            $row->forceFill([
                'is_enabled' => $isEnabled,
                'client_id' => $this->normalize($data['client_id'] ?? null),
                'redirect_uri' => $this->normalize($data['redirect_uri'] ?? null),
                'frontend_url' => $this->normalize($data['frontend_url'] ?? null),
            ])->save();
        }

        ActivityLogger::log($actor->id, $this->subject($scopeKey, $modelClass), $event, null, [
            'actor_role' => $actor->role?->slug,
            'agent_id' => $logAgentId,
            // Field NAMES only — the secret value never reaches the audit log.
            'fields' => array_keys(array_filter([
                'client_id' => isset($data['client_id']),
                'redirect_uri' => isset($data['redirect_uri']),
                'frontend_url' => isset($data['frontend_url']),
            ])),
            'secret_changed' => $secretChanged,
            'is_enabled' => $isEnabled,
            'had_secret_before' => $before['has_secret'],
            'has_secret_after' => filled($row->client_secret),
        ]);

        return $this->presentRow($scopeKey, $modelClass);
    }

    /**
     * Config rows read their secret through the model cast. This resolves the
     * plaintext so rows can be compared between before/after states and so a
     * "blank secret keeps the old one" write knows whether anything changed.
     */
    private function presentRow(int $scopeKey, string $modelClass): array
    {
        $row = $modelClass === GoogleAuthSetting::class
            ? GoogleAuthSetting::query()->whereKey($scopeKey)->first()
            : AgentGoogleAuthConfig::query()->where('agent_id', $scopeKey)->first();

        return [
            'has_secret' => $row !== null && filled($row->client_secret),
        ];
    }

    private function subject(int $scopeKey, string $modelClass): Model
    {
        $row = $modelClass === GoogleAuthSetting::class
            ? GoogleAuthSetting::singleton()
            : AgentGoogleAuthConfig::query()->where('agent_id', $scopeKey)->first();

        return $row ?? new $modelClass;
    }

    /** @return array{is_enabled:bool, client_id:?string, client_secret:?string, redirect_uri:?string, frontend_url:?string, source:string} */
    private function present(Model $row, string $source): array
    {
        return [
            'is_enabled' => $row->is_enabled,
            'client_id' => $row->client_id,
            'client_secret' => $row->client_secret,
            'redirect_uri' => $row->redirect_uri,
            'frontend_url' => $row->frontend_url,
            'source' => $source,
        ];
    }

    private function applySecretChange(GoogleAuthSetting $row, ?string $newSecret, bool $clear): bool
    {
        $old = $row->client_secret;
        if ($clear) {
            $row->client_secret = null;
            return filled($old);
        }
        if (filled($newSecret)) {
            $row->client_secret = $newSecret;
            return true;
        }
        // blank keeps the previous value
        return false;
    }

    private function applySecretChangeToModel(
        AgentGoogleAuthConfig $row,
        ?string $newSecret,
        bool $clear,
        ?string $oldPlain,
    ): bool {
        if ($clear) {
            $row->client_secret = null;
            return filled($oldPlain);
        }
        if (filled($newSecret)) {
            $row->client_secret = $newSecret;
            return true;
        }
        return false;
    }

    private function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}