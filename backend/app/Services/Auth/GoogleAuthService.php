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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google sign-in / registration / explicit account linking for KONSUMEN.
 *
 * Registration behavior:
 * - Referral supplied: resolve and validate the canonical chain through ReferralService.
 * - Referral empty: attach the new konsumen to the first active default agent (role_id=4).
 * - Existing Google identities and existing local accounts never have ownership fields reassigned.
 * - Login mode never creates a new account.
 *
 * OAuth state, nonce, PKCE verifier and provider snapshot are stored in the session and consumed once.
 */
class GoogleAuthService
{
    public const MODE_LOGIN = 'login';
    public const MODE_REGISTER = 'register';
    public const MODE_LINK = 'link';

    private const SESSION_KEY = 'google_oauth';
    private const FLOW_TTL_SECONDS = 600;
    private const GOOGLE_CERTS_CACHE_KEY = 'google_oauth_public_certs';
    private const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    public function __construct(
        private readonly ReferralService $referralService,
        private readonly GoogleAuthConfigService $configService,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) config('services.google.is_enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /** Start OAuth and return Google's authorization URL. */
    public function beginAuthorization(Request $request, string $mode, ?string $referralCode): string
    {
        $mode = in_array($mode, [self::MODE_LOGIN, self::MODE_REGISTER, self::MODE_LINK], true)
            ? $mode
            : self::MODE_LOGIN;

        $referralCode = $referralCode !== null ? strtoupper(trim($referralCode)) : '';
        $linkUserId = null;
        $agentId = null;

        if ($mode === self::MODE_LINK) {
            $current = Auth::guard('web')->user();
            if (! $this->isEligibleKonsumen($current)) {
                throw new ApiException(__('messages.google.link_login_required'), 401);
            }

            $linkUserId = (int) $current->id;
            $agentId = $current->agent_id !== null ? (int) $current->agent_id : null;
            $referralCode = '';
        } elseif ($mode === self::MODE_REGISTER) {
            // Fail fast before sending the user to Google. The chain is resolved again at callback.
            $chain = $this->resolveRegistrationChain($referralCode);
            $agentId = (int) $chain['agent_id'];
        }

        // Resolve effective config: agent -> global -> .env fallback.
        $settings = $this->configService->resolvedForAgent($agentId);
        $this->bindProviderConfig($settings);

        if (! $this->isConfigured()) {
            throw new ApiException(__('messages.google.not_configured'), 503);
        }

        $redirectUri = $this->defaultRedirectUri();
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
            'provider' => [
                'client_id' => $settings['client_id'] ?? null,
                'client_secret' => $settings['client_secret'] ?? null,
                'redirect_uri' => $redirectUri,
                'frontend_url' => $settings['frontend_url'] ?? null,
                'source' => $settings['source'] ?? 'unknown',
                'is_enabled' => (bool) ($settings['is_enabled'] ?? false),
            ],
            'created_at' => now()->timestamp,
        ]);

        $authUrl = (string) config('services.google.auth_url', 'https://accounts.google.com/o/oauth2/v2/auth');

        return $authUrl.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
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
     * Complete OAuth callback.
     * @return array{status:'authenticated',user:User,created:bool}|array{status:'linked',user:User}|array{status:'error',code:string,mode:string}
     */
    public function handleCallback(Request $request): array
    {
        $flow = $request->session()->pull(self::SESSION_KEY);
        $mode = is_array($flow) ? ($flow['mode'] ?? self::MODE_LOGIN) : self::MODE_LOGIN;
        $fail = static fn (string $code): array => ['status' => 'error', 'code' => $code, 'mode' => $mode];

        if (! is_array($flow)) {
            return $fail('invalid_state');
        }

        $bound = $flow['provider'] ?? null;
        if (! is_array($bound)
            || ! filled($bound['client_id'] ?? null)
            || ! filled($bound['client_secret'] ?? null)
            || ! filled($flow['state'] ?? null)
            || ! filled($flow['nonce'] ?? null)
            || ! filled($flow['verifier'] ?? null)) {
            return $fail('invalid_state');
        }

        // Re-bind the exact provider snapshot captured when OAuth started.
        $this->bindProviderConfig([
            'client_id' => $bound['client_id'] ?? null,
            'client_secret' => $bound['client_secret'] ?? null,
            'redirect_uri' => $bound['redirect_uri'] ?? null,
            'frontend_url' => $bound['frontend_url'] ?? null,
            'source' => $bound['source'] ?? 'unknown',
            'is_enabled' => $bound['is_enabled'] ?? false,
        ]);

        // A provider disabled while the flow was in progress must not complete.
        $currentSettings = $this->configService->resolvedForAgent(
            isset($flow['agent_id']) ? (int) $flow['agent_id'] : null
        );
        if (! $this->isConfigured() || ! (bool) ($currentSettings['is_enabled'] ?? false)) {
            return $fail('invalid_state');
        }

        $createdAt = (int) ($flow['created_at'] ?? 0);
        if ($createdAt <= 0 || now()->timestamp < $createdAt || now()->timestamp - $createdAt > self::FLOW_TTL_SECONDS) {
            return $fail('invalid_state');
        }

        $returnedState = (string) $request->query('state', '');
        if ($returnedState === '' || ! hash_equals((string) $flow['state'], $returnedState)) {
            return $fail('invalid_state');
        }

        if ($request->query('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $fail('google_denied');
        }

        $claims = $this->exchangeCodeForClaims(
            (string) $request->query('code'),
            (string) $flow['verifier'],
            (string) $flow['nonce']
        );

        if ($claims === null) {
            $this->auditBrokerFailure($flow, 'provider_error');
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

        // Already linked Google identity: login only; never change ownership fields.
        $identity = UserSocialIdentity::query()
            ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
            ->where('provider_user_id', $sub)
            ->first();

        if ($identity) {
            $user = User::query()->with('role')->find($identity->user_id);
            if (! $this->isEligibleKonsumen($user)) {
                return $fail('account_unavailable');
            }

            $identity->update(['provider_email' => $email, 'last_login_at' => now()]);
            return $this->authenticated($user, false, 'auth.google_login', $flow);
        }

        // Existing local account: only auto-link if the local email is already verified.
        $existing = User::query()->withTrashed()->with('role')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            if (! $this->isEligibleKonsumen($existing)) {
                return $fail('email_in_use');
            }
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

        // Unknown accounts may only be created in register mode.
        if ($mode !== self::MODE_REGISTER) {
            return $fail('registration_required');
        }

        $referralCode = trim((string) ($flow['referral_code'] ?? ''));
        try {
            if ($referralCode !== '') {
                // Referral chain is always re-resolved server-side at callback time.
                $chain = $this->referralService->resolveChainByCode($referralCode);
            } else {
                // Revalidate the exact default agent selected when this flow started.
                $agentId = (int) ($flow['agent_id'] ?? 0);
                $chain = $agentId > 0 ? $this->resolveDefaultAgentChain($agentId) : null;
                if ($chain === null) {
                    return $fail('default_agent_unavailable');
                }
            }
        } catch (ApiException) {
            return $fail($referralCode !== '' ? 'invalid_referral' : 'default_agent_unavailable');
        }

        $konsumenRoleId = Role::query()->where('slug', 'konsumen')->value('id');
        if (! $konsumenRoleId) {
            Log::error('google_auth.konsumen_role_missing');
            return $fail('registration_unavailable');
        }

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
            'referrer_id' => $chain['parent_id'],
            'agent_id' => $chain['agent_id'],
            'korsal_id' => $chain['korsal_id'],
            'sales_id' => $chain['sales_id'],
        ]);
    }

    /** Choose the first active agent. Replace this with an explicit default-agent setting if available. */
    private function findDefaultAgentId(): ?int
    {
        $agent = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'agen'))
            ->where('status', 'active')
            ->orderBy('id')
            ->first(['id']);

        return $agent ? (int) $agent->id : null;
    }

    /** @return array{parent_id:int,agent_id:int,korsal_id:null,sales_id:null}|null */
    private function resolveDefaultAgentChain(int $agentId): ?array
    {
        $agent = User::query()
            ->whereKey($agentId)
            ->whereHas('role', fn ($query) => $query->where('slug', 'agen'))
            ->where('status', 'active')
            ->first(['id']);

        if (! $agent) {
            return null;
        }

        return [
            'parent_id' => (int) $agent->id,
            'agent_id' => (int) $agent->id,
            'korsal_id' => null,
            'sales_id' => null,
        ];
    }

    /** @return array{parent_id:mixed,agent_id:mixed,korsal_id:mixed,sales_id:mixed} */
    private function resolveRegistrationChain(string $referralCode): array
    {
        if ($referralCode !== '') {
            return $this->referralService->resolveChainByCode($referralCode);
        }

        $agentId = $this->findDefaultAgentId();
        if ($agentId === null) {
            throw new ApiException(
                'Agen default tidak ditemukan atau tidak aktif.',
                422,
                ['default_agent' => 'Agen default tidak tersedia.']
            );
        }

        $chain = $this->resolveDefaultAgentChain($agentId);
        if ($chain === null) {
            throw new ApiException(
                'Agen default tidak tersedia.',
                422,
                ['default_agent' => 'Agen default tidak tersedia.']
            );
        }

        return $chain;
    }

    /** Explicit link from the currently authenticated konsumen. */
    private function completeExplicitLink(int $flowUserId, string $sub, string $email, \Closure $fail): array
    {
        $current = Auth::guard('web')->user();
        if ($flowUserId === 0 || ! $this->isEligibleKonsumen($current) || (int) $current->id !== $flowUserId) {
            return $fail('invalid_state');
        }

        $owner = UserSocialIdentity::query()
            ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
            ->where('provider_user_id', $sub)
            ->first();

        if ($owner) {
            return (int) $owner->user_id === (int) $current->id
                ? ['status' => 'linked', 'user' => $current]
                : $fail('identity_in_use');
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
            'actor_role' => 'konsumen',
            'method' => 'explicit',
            'provider_email' => $email,
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

    private function authenticated(User $user, bool $created, string $event, array $flow, array $extra = []): array
    {
        ActivityLogger::log($user->id, $user, $event, null, [
            'actor_role' => 'konsumen',
            'referral_ignored' => ! $created && ($flow['referral_code'] ?? '') !== '',
            ...$extra,
        ]);

        return ['status' => 'authenticated', 'user' => $user, 'created' => $created];
    }

    /** Exchange the authorization code and validate the signed Google ID token. */
    private function exchangeCodeForClaims(string $code, string $verifier, string $nonce): ?array
    {
        try {
            $response = Http::asForm()->timeout(10)->post((string) config('services.google.token_url', 'https://oauth2.googleapis.com/token'), [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->defaultRedirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]);
        } catch (\Throwable $e) {
            Log::warning('google_auth.token_exchange_unreachable', ['exception' => class_basename($e)]);
            return null;
        }

        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            Log::warning('google_auth.token_exchange_failed', ['status' => $response->status()]);
            return null;
        }

        return $this->verifyIdToken($idToken, $nonce);
    }

    /** Verify JWT signature and the Google OIDC claims before trusting any claim. */
    private function verifyIdToken(string $idToken, string $expectedNonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            Log::warning('google_auth.id_token_invalid_format');
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode((string) $this->base64UrlDecode($encodedHeader), true);
        $claims = json_decode((string) $this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (! is_array($header) || ! is_array($claims) || $signature === false
            || ($header['alg'] ?? null) !== 'RS256' || ! filled($header['kid'] ?? null)) {
            Log::warning('google_auth.id_token_invalid_header');
            return null;
        }

        $kid = (string) $header['kid'];
        $certs = $this->googleCertificates(false);
        if (! isset($certs[$kid]) || ! is_string($certs[$kid])) {
            $certs = $this->googleCertificates(true);
        }

        $certificate = $certs[$kid] ?? null;
        if (! is_string($certificate) || $certificate === '') {
            Log::warning('google_auth.id_token_unknown_key');
            return null;
        }

        $verified = openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $certificate, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            Log::warning('google_auth.id_token_bad_signature');
            return null;
        }

        $clientId = (string) config('services.google.client_id');
        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_string($audience)
            ? hash_equals($clientId, $audience)
            : (is_array($audience) && in_array($clientId, $audience, true));

        $issuerValid = in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true);
        $now = now()->timestamp;
        $exp = (int) ($claims['exp'] ?? 0);
        $iat = (int) ($claims['iat'] ?? 0);
        $nonce = (string) ($claims['nonce'] ?? '');

        if (! $issuerValid
            || ! $audienceMatches
            || (isset($claims['azp']) && ! hash_equals($clientId, (string) $claims['azp']))
            || $exp <= $now
            || $iat <= 0
            || $iat > $now + 60
            || ! hash_equals($expectedNonce, $nonce)
            || ! filled($claims['sub'] ?? null)
            || ! filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL)
            || (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? null) !== 'true')) {
            Log::warning('google_auth.id_token_claims_invalid');
            return null;
        }

        return $claims;
    }

    /** @return array<string,string> */
    private function googleCertificates(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::GOOGLE_CERTS_CACHE_KEY);
        }

        try {
            $certs = Cache::remember(self::GOOGLE_CERTS_CACHE_KEY, now()->addHour(), function () {
                $response = Http::timeout(10)->get(self::GOOGLE_CERTS_URL);
                if (! $response->successful()) {
                    throw new \RuntimeException('Google certificate endpoint returned a non-success status.');
                }

                $json = $response->json();
                if (! is_array($json)) {
                    throw new \RuntimeException('Google certificate response was invalid.');
                }

                return $json;
            });

            return is_array($certs) ? $certs : [];
        } catch (\Throwable $e) {
            Log::warning('google_auth.certificate_fetch_failed', ['exception' => class_basename($e)]);
            return [];
        }
    }

    private function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    /** Bind effective provider settings to this request. */
    private function bindProviderConfig(array $settings): void
    {
        config([
            'services.google.is_enabled' => (bool) ($settings['is_enabled'] ?? false),
            'services.google.client_id' => $settings['client_id'] ?? null,
            'services.google.client_secret' => $settings['client_secret'] ?? null,
            'services.google.redirect_uri' => $settings['redirect_uri'] ?? null,
            'services.google.frontend_url' => $settings['frontend_url'] ?? null,
        ]);
    }

    private function defaultRedirectUri(): string
    {
        return config('services.google.redirect_uri')
            ?: rtrim((string) config('app.url'), '/').'/api/v1/auth/google/callback';
    }

    private function auditBrokerFailure(array $flow, string $code): void
    {
        $user = Auth::guard('web')->user();
        ActivityLogger::log($user?->id, $user ?? new User, 'auth.google_provider_error', null, [
            'actor_role' => $user?->role?->slug,
            'mode' => $flow['mode'] ?? self::MODE_LOGIN,
            'error' => $code,
            'provider_source' => $flow['provider']['source'] ?? 'unknown',
        ]);
    }
}
