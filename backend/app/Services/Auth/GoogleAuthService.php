<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Services\Logging\ActivityLogger;
use App\Services\Referral\ReferralService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * IMP-001 Feature A — Google sign-in for KONSUMEN.
 *
 * Google is an identity provider only. It never bypasses referral rules:
 *  - an already-linked Google identity (or an eligible existing konsumen whose OWN email was verified
 *    and matches the verified Google email) just logs in; an unverified local account is never auto-linked
 *    (explicit MODE_LINK from an authenticated session is the secure path) — the referral context is ignored and NO ownership field is touched
 *    (reassignment stays exclusively in ReferralReassignmentService);
 *  - a NEW konsumen is created only in `register` mode with a referral code that is re-validated
 *    and re-resolved server-side at callback time through ReferralService (the canonical chain);
 *  - `login` mode never creates a konsumen.
 *
 * Flow state (CSRF `state`, `nonce`, PKCE verifier, mode, referral CODE) lives in the server-side
 * session and is consumed exactly once. No OAuth token is persisted or logged.
 */
class GoogleAuthService
{
    public const MODE_LOGIN = 'login';

    public const MODE_REGISTER = 'register';

    /** Explicit linking from an authenticated session (the only way an existing local account gets a Google identity without a verified email). */
    public const MODE_LINK = 'link';

    private const SESSION_KEY = 'google_oauth';

    private const FLOW_TTL_SECONDS = 600;

    public function __construct(
        private readonly ReferralService $referralService,
        private readonly GoogleAuthConfigService $configService,
    ) {}

    public function isConfigured(): bool
    {
        // A1-10: credentials existing is NOT enough — an explicitly disabled
        // effective configuration must refuse to start/continue a flow.
        return (bool) config('services.google.is_enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /**
     * Starts the flow and returns the Google authorization URL.
     *
     * @throws ApiException 422 when mode=register has no valid referral; 503 when Google is not configured
     */
    public function beginAuthorization(Request $request, string $mode, ?string $referralCode): string
    {
        // IMP-001 Step 3: resolve the branch's effective provider config
        // (Agen row -> global row -> .env fallback) and BIND the flow to it —
        // the exact provider that started the transaction is the one the
        // callback validates against, so an agent's referral can never be
        // paired with another branch's (or the global) credentials.
        $referralCode = $referralCode !== null ? strtoupper(trim($referralCode)) : '';
        $linkUserId = null;
        $agentId = null;

        if ($mode === self::MODE_LINK) {
            $current = Auth::guard('web')->user();
            if (! $this->isEligibleKonsumen($current)) {
                throw new ApiException(__('messages.google.link_login_required'), 401);
            }
            $linkUserId = $current->id;
            $referralCode = '';
            $agentId = $current->agent_id;
        } elseif ($mode === self::MODE_REGISTER) {
            if ($referralCode === '') {
                throw new ApiException(__('messages.google.referral_required'), 422, ['referral_code' => __('messages.google.referral_required')]);
            }
            // Fail fast with the canonical validation; the chain is re-resolved again at callback.
            $chain = $this->referralService->resolveChainByCode($referralCode);
            $agentId = (int) $chain['agent_id'];
        }

        // bind for the whole request: the callback leg validates the id_token
        // against this same resolved provider (see bindProviderConfig()).
        $settings = $this->configService->resolvedForAgent($agentId);
        $this->bindProviderConfig($settings);

        // A1-10: an explicit `is_enabled=false` (branch or global, even with
        // valid credentials present) MUST refuse to start a fresh flow.
        if (! $this->isConfigured()) {
            throw new ApiException(__('messages.google.not_configured'), 503);
        }

        $state = Str::random(48);
        $nonce = Str::random(48);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'mode' => $mode,
            'referral_code' => $referralCode,
            'link_user_id' => $linkUserId,
            'agent_id' => $agentId,
            // Provider snapshot: which branch's (or the global) credentials
            // started this flow — the callback validates against these exact
            // values, so a concurrent admin/agent config change mid-flow
            // cannot redirect the transaction to different credentials.
            'provider' => [
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => $this->defaultRedirectUri(),
                'frontend_url' => $settings['frontend_url'],
                'source' => $settings['source'],
                // A1-10: also snapshot the effective is_enabled, so a provider
                // disabled mid-flow is refused on the callback leg too.
                'is_enabled' => $settings['is_enabled'],
            ],
            'created_at' => now()->timestamp,
        ]);

        return config('services.google.auth_url').'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->defaultRedirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Completes the flow.
     *
     * @return array{status:'authenticated', user:User, created:bool}|array{status:'linked', user:User}|array{status:'error', code:string, mode:string}
     */
    public function handleCallback(Request $request): array
    {
        $flow = $request->session()->pull(self::SESSION_KEY); // one-time use: replay finds nothing
        $mode = $flow['mode'] ?? self::MODE_LOGIN;

        $fail = fn (string $code): array => ['status' => 'error', 'code' => $code, 'mode' => $mode];

        if (! is_array($flow)) {
            return $fail('invalid_state');
        }

        // IMP-001 Step 3: the callback validates the id_token against the
        // EXACT provider snapshot bound at redirect time. A concurrent
        // admin/agent config change or a mismatched source (agent vs global)
        // is refused with a broker error rather than silently validated
        // against different credentials. `aud` mismatch is enforced inside
        // exchangeCodeForClaims (compare with the bound client_id).
        //
        // A1-11: bind the flow's provider snapshot BEFORE any availability
        // check. In a fresh request/app lifecycle the runtime config carries
        // only the .env values (usually empty); DB-only configuration must
        // still complete the callback. `isConfigured()` (which now also
        // honours the snapshot's is_enabled) runs AFTER the bind.
        $bound = $flow['provider'] ?? null;
        if (! is_array($bound)) {
            return $fail('invalid_state');
        }
        $this->bindProviderConfig([
            'client_id' => $bound['client_id'] ?? null,
            'client_secret' => $bound['client_secret'] ?? null,
            'redirect_uri' => $bound['redirect_uri'] ?? null,
            'frontend_url' => $bound['frontend_url'] ?? null,
            'source' => $bound['source'] ?? 'unknown',
            'is_enabled' => $bound['is_enabled'] ?? true,
        ]);

        // A flow started with a now-DISABLED provider is a stale/disabled flow —
        // refuse it deterministically (A1-10 semantics preserved on the callback
        // leg too).
        $currentSettings = $this->configService->resolvedForAgent($flow['agent_id'] ?? null);
        if (! $this->isConfigured() || ! $currentSettings['is_enabled']) {
            return $fail('invalid_state');
        }

        if (now()->timestamp - (int) ($flow['created_at'] ?? 0) > self::FLOW_TTL_SECONDS) {
            return $fail('invalid_state');
        }

        $returnedState = (string) $request->query('state', '');
        if ($returnedState === '' || ! hash_equals((string) $flow['state'], $returnedState)) {
            return $fail('invalid_state');
        }

        if ($request->query('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $fail('google_denied');
        }

        $claims = $this->exchangeCodeForClaims((string) $request->query('code'), (string) $flow['verifier'], (string) $flow['nonce']);
        if ($claims === null) {
            $this->auditBrokerFailure($request, $flow, 'provider_error');

            return $fail('provider_error');
        }

        if (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? null) !== 'true') {
            return $fail('email_unverified');
        }

        $sub = (string) $claims['sub'];
        $email = Str::lower(trim((string) $claims['email']));

        if ($mode === self::MODE_LINK) {
            return $this->completeExplicitLink((int) ($flow['link_user_id'] ?? 0), $sub, $email, $fail);
        }

        // 1) Already-linked identity → plain login. Referral context is intentionally ignored.
        $identity = UserSocialIdentity::query()
            ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)->where('provider_user_id', $sub)->first();

        if ($identity) {
            $user = User::query()->with('role')->find($identity->user_id);
            if (! $this->isEligibleKonsumen($user)) {
                return $fail('account_unavailable');
            }
            $identity->update(['provider_email' => $email, 'last_login_at' => now()]);

            return $this->authenticated($user, false, 'auth.google_login', $flow);
        }

        // 2) Eligible existing konsumen with the same verified email → link + login. Never reassigns.
        $existing = User::query()->withTrashed()->with('role')->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            if (! $this->isEligibleKonsumen($existing)) {
                return $fail('email_in_use');
            }

            // Local emails are NOT verified at registration, so a matching address alone proves nothing:
            // anyone could have pre-registered a victim's email. Auto-link only when the CURRENT local
            // email is itself verified (ProfileController resets email_verified_at on any address change,
            // so a non-null timestamp always refers to the current address, not a stale one); otherwise
            // the owner must sign in locally and link explicitly (MODE_LINK).
            if ($existing->email_verified_at === null) {
                return $fail('local_account_exists');
            }

            try {
                DB::transaction(fn () => $this->linkIdentity($existing, $sub, $email));
            } catch (UniqueConstraintViolationException) {
                return $fail('conflict');
            }

            return $this->authenticated($existing, false, 'auth.google_linked', $flow, ['method' => 'verified_email']);
        }

        // 3) Unknown to PrimeClassy → never silently create.
        if ($mode !== self::MODE_REGISTER || ($flow['referral_code'] ?? '') === '') {
            return $fail('registration_required');
        }

        try {
            // Referral re-validated and the canonical hierarchy re-resolved server-side right now.
            $chain = $this->referralService->resolveChainByCode((string) $flow['referral_code']);
        } catch (ApiException) {
            return $fail('invalid_referral');
        }

        $konsumenRoleId = Role::query()->where('slug', 'konsumen')->value('id');

        try {
            $user = DB::transaction(function () use ($claims, $email, $sub, $chain, $konsumenRoleId) {
                $user = User::create([
                    'role_id' => $konsumenRoleId,
                    'parent_id' => $chain['parent_id'],
                    'agent_id' => $chain['agent_id'],
                    'korsal_id' => $chain['korsal_id'],
                    'sales_id' => $chain['sales_id'],
                    'name' => Str::limit((string) ($claims['name'] ?? Str::before($email, '@')), 150, ''),
                    'email' => $email,
                    'phone' => null,
                    // Unusable random password: the account signs in through Google (or a later reset).
                    'password' => Str::random(48),
                    'status' => 'active',
                ]);

                $this->linkIdentity($user, $sub, $email);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return $fail('conflict');
        }

        return $this->authenticated($user->load('role'), true, 'auth.google_registered', $flow, [
            'referrer_id' => $chain['parent_id'], 'agent_id' => $chain['agent_id'],
            'korsal_id' => $chain['korsal_id'], 'sales_id' => $chain['sales_id'],
        ]);
    }

    /**
     * Explicit link by the currently authenticated konsumen. The Google email need not match (the
     * session proves ownership of the local account); a Google identity already owned by anyone else,
     * or a user who already has a Google identity, is refused. Referral/ownership fields are untouched.
     *
     * @return array{status:'linked', user:User}|array{status:'error', code:string, mode:string}
     */
    private function completeExplicitLink(int $flowUserId, string $sub, string $email, \Closure $fail): array
    {
        $current = Auth::guard('web')->user();

        if ($flowUserId === 0 || ! $this->isEligibleKonsumen($current) || $current->id !== $flowUserId) {
            return $fail('invalid_state');
        }

        $owner = UserSocialIdentity::query()
            ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)->where('provider_user_id', $sub)->first();

        if ($owner) {
            return $owner->user_id === $current->id ? ['status' => 'linked', 'user' => $current] : $fail('identity_in_use');
        }

        if ($current->socialIdentities()->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)->exists()) {
            return $fail('already_linked');
        }

        try {
            DB::transaction(fn () => $this->linkIdentity($current, $sub, $email));
        } catch (UniqueConstraintViolationException) {
            return $fail('identity_in_use');
        }

        ActivityLogger::log($current->id, $current, 'auth.google_linked', null, [
            'actor_role' => 'konsumen', 'method' => 'explicit', 'provider_email' => $email,
        ]);

        return ['status' => 'linked', 'user' => $current];
    }

    private function linkIdentity(User $user, string $sub, string $email): void
    {
        UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => UserSocialIdentity::PROVIDER_GOOGLE,
            'provider_user_id' => $sub,
            'provider_email' => $email,
            'last_login_at' => now(),
        ]);
    }

    private function isEligibleKonsumen(?User $user): bool
    {
        return $user !== null
            && ! $user->trashed()
            && $user->status === 'active'
            && $user->isRole('konsumen');
    }

    /** @return array{status:'authenticated', user:User, created:bool} */
    private function authenticated(User $user, bool $created, string $event, array $flow, array $extra = []): array
    {
        ActivityLogger::log($user->id, $user, $event, null, [
            'actor_role' => 'konsumen',
            // A referral code supplied alongside an existing account is recorded as ignored — nothing changed.
            'referral_ignored' => ! $created && ($flow['referral_code'] ?? '') !== '',
            ...$extra,
        ]);

        return ['status' => 'authenticated', 'user' => $user, 'created' => $created];
    }

    /**
     * Back-channel code exchange (TLS to Google's token endpoint, client secret + PKCE verifier) and
     * validation of the returned ID token claims. Returns null on any failure; nothing sensitive is logged.
     *
     * @return array<string,mixed>|null
     */
    private function exchangeCodeForClaims(string $code, string $verifier, string $nonce): ?array
    {
        try {
            $response = Http::asForm()->timeout(10)->post(config('services.google.token_url'), [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->defaultRedirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]);
        } catch (\Throwable) {
            Log::warning('google_auth.token_exchange_unreachable');

            return null;
        }

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            Log::warning('google_auth.token_exchange_failed', ['status' => $response->status()]);

            return null;
        }

        $parts = explode('.', $response->json('id_token'));
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;

        if (! is_array($claims)
            || ! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== config('services.google.client_id')
            || (int) ($claims['exp'] ?? 0) < now()->timestamp
            || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || ! filled($claims['sub'] ?? null)
            || ! filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            Log::warning('google_auth.id_token_invalid');

            return null;
        }

        return $claims;
    }

    /**
     * Merges the effective (per-agent / global / env) provider values into
     * the `services.google` config for the duration of this request. The
     * token exchange and id_token `aud`/iss checks read from here; the
     * callback re-binds the snapshot captured at redirect time.
     *
     * @param  array<string, mixed>  $settings
     */
    private function bindProviderConfig(array $settings): void
    {
        config([
            'services.google.is_enabled' => (bool) ($settings['is_enabled'] ?? true),
            'services.google.client_id' => $settings['client_id'] ?? null,
            'services.google.client_secret' => $settings['client_secret'] ?? null,
            'services.google.redirect_uri' => $settings['redirect_uri'] ?? null,
            'services.google.frontend_url' => $settings['frontend_url'] ?? null,
        ]);
    }

    private function defaultRedirectUri(): string
    {
        return config('services.google.redirect_uri') ?: rtrim((string) config('app.url'), '/').'/api/v1/auth/google/callback';
    }

    /** A provider failure during an authenticated flow — audit it (no secret, no code). */
    private function auditBrokerFailure(Request $request, array $flow, string $code): void
    {
        $mode = $flow['mode'] ?? 'login';
        $user = Auth::guard('web')->user();

        ActivityLogger::log($user?->id, $user ?? new User, 'auth.google_provider_error', null, [
            'actor_role' => $user?->role?->slug,
            'mode' => $mode,
            'error' => $code,
            'provider_source' => $flow['provider']['source'] ?? 'unknown',
        ]);
    }
}
