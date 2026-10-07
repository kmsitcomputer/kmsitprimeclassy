<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * IMP-001 Feature A: Google sign-in for konsumen. Google is identity only — a NEW konsumen needs a valid
 * referral resolved server-side; an existing account is never reassigned.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config([
            'services.google.client_id' => 'client-123', 'services.google.client_secret' => 'secret-xyz',
            'services.google.redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
            'services.google.frontend_url' => '',
        ]);
        config(['services.google.is_enabled' => true]);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create(['referral_code' => 'AG-T1']);
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko G', 'address' => 'Jl. G', 'latitude' => -6.2, 'longitude' => 106.8]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id, 'referral_code' => 'KO-T1']);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id, 'referral_code' => 'SA-T1']);
        $other = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id, 'referral_code' => 'SA-T2']);

        return compact('agen', 'korsal', 'sales', 'other');
    }

    private function idToken(array $override = []): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com', 'aud' => 'client-123', 'exp' => time() + 600,
            'sub' => 'g-sub-1', 'email' => 'new.person@gmail.com', 'email_verified' => true, 'name' => 'New Person',
        ], $override);
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256']).'.'.$b64($claims).'.sig';
    }

    /** Runs redirect + callback with a faked Google. Returns the callback response. */
    private function runFlow(string $mode, ?string $referral, array $claims = [], ?callable $between = null, ?string $stateOverride = null)
    {
        $query = ['mode' => $mode] + ($referral !== null ? ['referral_code' => $referral] : []);
        $redirect = $this->get('/api/v1/auth/google/redirect?'.http_build_query($query));

        if (! str_starts_with((string) $redirect->headers->get('Location'), 'https://accounts.google.com/')) {
            return $redirect;
        }

        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $params);
        $cookieName = config('session.cookie');
        $cookie = $redirect->getCookie($cookieName);

        // Fresh factory per flow: a stub registered earlier in the same test would otherwise keep winning.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken($claims + ['nonce' => $params['nonce']])])]);

        if ($between) {
            $between();
        }

        return $this->withCookie($cookieName, $cookie->getValue())
            ->get('/api/v1/auth/google/callback?'.http_build_query(['code' => 'auth-code', 'state' => $stateOverride ?? $params['state']]));
    }

    public function test_register_without_referral_never_starts_and_creates_nothing(): void
    {
        $this->runFlow('register', null)->assertRedirect('/register?google_error=referral_required');
        $this->runFlow('register', 'NOPE-1')->assertRedirect('/register?google_error=referral_required');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
    }

    // ---- A1-10: enable/disable switch ----

    public function test_disabled_global_config_refuses_new_oauth_flow_even_with_valid_credentials(): void
    {
        // The global row carries VALID credentials but is explicitly disabled.
        \App\Models\GoogleAuthSetting::singleton()->forceFill([
            'is_enabled' => false,
            'client_id' => 'db-client-enabled',
            'client_secret' => encrypt('db-secret-enabled'),
            'redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
            'frontend_url' => '',
        ])->save();
        // Strip the env fallback so the DB row is authoritative.
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        config(['services.google.is_enabled' => false]);

        // A disabled provider must NOT navigate to accounts.google.com — it is
        // refused with not_configured and nothing is created.
        $this->get('/api/v1/auth/google/redirect?mode=login')
            ->assertRedirect('/login?google_error=not_configured');
        $this->assertDatabaseCount('user_social_identities', 0);
    }

    public function test_enabled_global_config_with_credentials_starts_flow_and_completes_registration(): void
    {
        \App\Models\GoogleAuthSetting::singleton()->forceFill([
            'is_enabled' => true,
            'client_id' => 'client-123',
            'client_secret' => encrypt('secret-xyz'),
            'redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
            'frontend_url' => '',
        ])->save();

        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->branch();
        $this->runFlow('register', 'sa-t1')->assertRedirect('/?google=registered');
        $this->assertDatabaseHas('users', ['email' => 'new.person@gmail.com', 'agent_id' => $agen->id]);
    }

    public function test_provider_disabled_after_redirect_refuses_callback_before_token_exchange(): void
    {
        \App\Models\GoogleAuthSetting::singleton()->forceFill(['is_enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'secret-xyz'])->save();
        $this->branch();
        $this->runFlow('register', 'SA-T1', between: function () {
            \App\Models\GoogleAuthSetting::query()->whereKey(1)->update(['is_enabled' => false]);
        })->assertRedirect('/register?google_error=invalid_state');
        Http::assertNothingSent();
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
    }

    // ---- A1-11: DB-only callback in a fresh request lifecycle ----

    public function test_db_only_callback_completes_when_environment_credentials_are_empty(): void
    {
        \App\Models\GoogleAuthSetting::singleton()->forceFill([
            'is_enabled' => true,
            'client_id' => 'client-123',
            'client_secret' => encrypt('secret-xyz'),
            'redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
            'frontend_url' => '',
        ])->save();

        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->branch();

        // The redirect STARTED the flow via the DB row (agent row absent → global row).
        // Before the callback, simulate a FRESH application lifecycle by wiping the
        // in-memory env-only config — a real second HTTP request would boot without
        // any env GOOGLE_CLIENT_ID/SECRET, so this is the truthful reproduction.
        $this->runFlow('register', 'sa-t1', ['email' => 'new.person@gmail.com'], between: function () {
            config([
                'services.google.client_id' => null,
                'services.google.client_secret' => null,
                'services.google.redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
                'services.google.is_enabled' => false,
            ]);
        })->assertRedirect('/?google=registered');

        $this->assertDatabaseHas('users', ['email' => 'new.person@gmail.com', 'agent_id' => $agen->id]);
    }

    public function test_register_with_valid_referral_creates_konsumen_with_canonical_chain_and_logs_in(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->branch();

        $this->runFlow('register', 'sa-t1')->assertRedirect('/?google=registered');

        $user = User::query()->where('email', 'new.person@gmail.com')->firstOrFail();
        $this->assertSame('konsumen', $user->role->slug);
        $this->assertSame([$agen->id, $korsal->id, $sales->id, $sales->id], [$user->agent_id, $user->korsal_id, $user->sales_id, $user->parent_id]);
        $this->assertDatabaseHas('user_social_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-sub-1']);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.google_registered', 'causer_id' => $user->id]);
    }

    public function test_login_mode_with_unknown_google_account_never_creates_a_konsumen(): void
    {
        $this->runFlow('login', null)->assertRedirect('/register?google_error=registration_required');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
        $this->assertDatabaseCount('user_social_identities', 0);
        $this->assertGuest();
    }

    public function test_linked_identity_logs_in_and_ignores_another_referral_link(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'other' => $other] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'new.person@gmail.com', 'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);
        UserSocialIdentity::create(['user_id' => $konsumen->id, 'provider' => 'google', 'provider_user_id' => 'g-sub-1']);
        $before = $konsumen->only(['agent_id', 'korsal_id', 'sales_id', 'parent_id']);

        $this->runFlow('register', $other->referral_code)->assertRedirect('/?google=ok');

        $this->assertAuthenticatedAs($konsumen);
        $this->assertSame($before, $konsumen->fresh()->only(['agent_id', 'korsal_id', 'sales_id', 'parent_id']));
        $this->assertDatabaseMissing('activity_logs', ['event' => 'referral.changed']);
    }

    public function test_unverified_local_account_is_never_auto_linked_by_email(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'other' => $other] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'new.person@gmail.com', 'email_verified_at' => null,
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        foreach ([['login', null], ['register', $other->referral_code]] as [$mode, $ref]) {
            $this->runFlow($mode, $ref)->assertRedirect('/'.($mode === 'login' ? 'login' : 'register').'?google_error=local_account_exists');
        }

        $this->assertDatabaseCount('user_social_identities', 0);
        $this->assertGuest();
        $this->assertSame($sales->id, $konsumen->fresh()->sales_id);
    }

    public function test_local_account_with_verified_email_is_linked_without_reassignment(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'other' => $other] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'new.person@gmail.com', 'email_verified_at' => now(),
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        $this->runFlow('register', $other->referral_code)->assertRedirect('/?google=ok');

        $this->assertDatabaseHas('user_social_identities', ['user_id' => $konsumen->id, 'provider_user_id' => 'g-sub-1']);
        $this->assertSame($sales->id, $konsumen->fresh()->sales_id);
        $this->assertSame(1, User::query()->where('email', 'new.person@gmail.com')->count());
    }

    public function test_explicit_link_requires_login_and_links_without_touching_referral(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'local@x.com', 'email_verified_at' => null,
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        // Not signed in → refused before reaching Google.
        $this->runFlow('link', null)->assertRedirect('/profile?google_error=login_required');

        // Signed in: the Google email may differ from the local one; the session proves ownership.
        $this->actingAs($konsumen);
        $this->runFlow('link', null)->assertRedirect('/profile?google=linked');
        $this->assertDatabaseHas('user_social_identities', ['user_id' => $konsumen->id, 'provider_user_id' => 'g-sub-1']);
        $this->assertSame($sales->id, $konsumen->fresh()->sales_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.google_linked', 'causer_id' => $konsumen->id]);

        // A second, different Google account cannot be stacked on the same user.
        $this->runFlow('link', null, ['sub' => 'g-sub-2'])->assertRedirect('/profile?google_error=already_linked');
    }

    public function test_explicit_link_refuses_a_google_identity_owned_by_someone_else(): void
    {
        $owner = User::factory()->konsumen()->create();
        UserSocialIdentity::create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-sub-1']);
        $me = User::factory()->konsumen()->create();

        $this->actingAs($me);
        $this->runFlow('link', null)->assertRedirect('/profile?google_error=identity_in_use');
        $this->assertDatabaseMissing('user_social_identities', ['user_id' => $me->id]);
    }

    public function test_provider_identity_wins_over_an_email_collision_and_never_switches_account(): void
    {
        $linked = User::factory()->konsumen()->create(['email' => 'first@x.com']);
        UserSocialIdentity::create(['user_id' => $linked->id, 'provider' => 'google', 'provider_user_id' => 'g-sub-1']);
        User::factory()->konsumen()->create(['email' => 'new.person@gmail.com', 'email_verified_at' => now()]);

        $this->runFlow('login', null)->assertRedirect('/?google=ok');

        $this->assertAuthenticatedAs($linked);
        $this->assertDatabaseCount('user_social_identities', 1);
    }

    public function test_email_of_a_staff_or_inactive_account_is_refused_and_nothing_is_created_or_linked(): void
    {
        ['sales' => $sales] = $this->branch();
        $sales->update(['email' => 'new.person@gmail.com']);

        $this->runFlow('register', 'SA-T1')->assertRedirect('/register?google_error=email_in_use');
        $this->assertDatabaseCount('user_social_identities', 0);
        $this->assertGuest();
    }

    public function test_state_mismatch_and_replay_are_rejected(): void
    {
        $this->branch();

        $this->runFlow('register', 'SA-T1', stateOverride: 'forged-state')->assertRedirect('/register?google_error=invalid_state');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);

        // The state is single-use: a second callback with the same (already consumed) session is rejected.
        $redirect = $this->get('/api/v1/auth/google/redirect?mode=register&referral_code=SA-T1');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $params);
        $cookie = $redirect->getCookie(config('session.cookie'))->getValue();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['nonce' => $params['nonce']])])]);
        $callback = '/api/v1/auth/google/callback?'.http_build_query(['code' => 'c', 'state' => $params['state']]);
        $this->withCookie(config('session.cookie'), $cookie)->get($callback)->assertRedirect('/?google=registered');
        auth()->guard('web')->logout();
        $this->withCookie(config('session.cookie'), $cookie)->get($callback)->assertRedirect('/login?google_error=invalid_state');
        $this->assertSame(1, User::query()->where('email', 'new.person@gmail.com')->count());

        // A callback with no preceding redirect (no session state) is rejected too.
        $this->get('/api/v1/auth/google/callback?code=x&state=y')->assertRedirect('/login?google_error=invalid_state');
    }

    public function test_nonce_audience_and_unverified_email_are_rejected(): void
    {
        $this->branch();

        $this->runFlow('register', 'SA-T1', ['nonce' => 'attacker-nonce'])->assertRedirect('/register?google_error=provider_error');
        $this->runFlow('register', 'SA-T1', ['aud' => 'someone-elses-client'])->assertRedirect('/register?google_error=provider_error');
        $this->runFlow('register', 'SA-T1', ['email_verified' => false])->assertRedirect('/register?google_error=email_unverified');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
    }

    public function test_expired_state_is_rejected(): void
    {
        $this->branch();

        $this->runFlow('register', 'SA-T1', between: fn () => $this->travel(11)->minutes())
            ->assertRedirect('/register?google_error=invalid_state');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
    }

    public function test_referral_revalidated_at_callback(): void
    {
        ['sales' => $sales] = $this->branch();

        $this->runFlow('register', 'SA-T1', between: fn () => $sales->update(['status' => 'inactive']))
            ->assertRedirect('/register?google_error=invalid_referral');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);
    }

    /** Audit #1 / Finding 4 (MINOR): a referral prefilled from ?ref= is used directly, never requiring a manual edit. */
    public function test_referral_prefilled_from_ref_param_is_used_directly_by_the_google_redirect(): void
    {
        ['sales' => $sales] = $this->branch();

        // The registration page prefills form.referral_code from ?ref=; the
        // Google button then starts the flow with that exact code — the backend
        // validates it immediately (fail-fast at redirect) AND re-resolves it
        // at callback (canonical chain). No manual re-entry is ever needed.
        $redirect = $this->get('/api/v1/auth/google/redirect?mode=register&referral_code='.$sales->referral_code);

        $redirect->assertRedirect();
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        // The exact prefilled code is in the browser leg (so Google shows the
        // right account) and the flow stores it server-side for the callback.
        $cookieName = config('session.cookie');
        $cookie = $redirect->getCookie($cookieName)->getValue();

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['nonce' => $params['nonce']])])]);

        $this->withCookie($cookieName, $cookie)
            ->get('/api/v1/auth/google/callback?'.http_build_query(['code' => 'auth-code', 'state' => $params['state']]))
            ->assertRedirect('/?google=registered');

        $user = User::query()->where('email', 'new.person@gmail.com')->firstOrFail();
        $this->assertSame($sales->id, $user->sales_id, 'the chain resolution used the prefilled code, not a re-typed one');
    }

    /** Audit #1 / Finding 4: an INVALID prefilled ?ref= is refused at redirect time, before any Google round-trip. */
    public function test_invalid_prefilled_referral_is_refused_at_redirect(): void
    {
        $this->get('/api/v1/auth/google/redirect?mode=register&referral_code=DOES-NOT-EXIST')
            ->assertRedirect('/register?google_error=referral_required');
    }

    public function test_one_google_identity_cannot_belong_to_two_users(): void
    {
        $a = User::factory()->konsumen()->create();
        $b = User::factory()->konsumen()->create();
        UserSocialIdentity::create(['user_id' => $a->id, 'provider' => 'google', 'provider_user_id' => 'dup']);

        $this->expectException(QueryException::class);
        UserSocialIdentity::create(['user_id' => $b->id, 'provider' => 'google', 'provider_user_id' => 'dup']);
    }

    /** Audit #1 / Finding 1 (MAJOR): a changed email must invalidate the old address's verification. */
    public function test_changing_email_invalidates_verification_and_blocks_auto_link_until_reverified(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'other' => $other] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'old-verified@gmail.com', 'email_verified_at' => now(),
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        // Change the address through the real profile API — the Google account signs in with the NEW email.
        $this->actingAs($konsumen)->patchJson('/api/v1/profile', ['email' => 'new.person@gmail.com'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $konsumen->id, 'email' => 'new.person@gmail.com', 'email_verified_at' => null]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.updated', 'causer_id' => $konsumen->id]);

        // A fresh browser (no session) now attempts the same Google account.
        $this->flushSession();

        // Auto-link must refuse: the current address was never verified (the old one no longer applies).
        $this->runFlow('login', null)->assertRedirect('/login?google_error=local_account_exists');
        $this->runFlow('register', $other->referral_code)->assertRedirect('/register?google_error=local_account_exists');
        $this->assertDatabaseCount('user_social_identities', 0);
        // The failed flows left the account exactly as it was (the redirects
        // above already prove no session was established as the konsumen).
        $this->assertDatabaseHas('users', [
            'id' => $konsumen->id,
            'email' => 'new.person@gmail.com',
            'email_verified_at' => null,
            'status' => 'active',
        ]);

        // Explicit linking from an authenticated session stays available (session proves ownership).
        $this->actingAs($konsumen);
        $this->runFlow('link', null)->assertRedirect('/profile?google=linked');
        $this->assertDatabaseHas('user_social_identities', ['user_id' => $konsumen->id, 'provider_user_id' => 'g-sub-1']);
    }

    /** Audit #1 / Finding 1 — regression guard: keeping the SAME email keeps its verification intact. */
    public function test_keeping_same_email_keeps_verification(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->branch();
        $konsumen = User::factory()->konsumen()->create([
            'email' => 'same@gmail.com', 'email_verified_at' => now(),
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        $this->actingAs($konsumen)->patchJson('/api/v1/profile', ['email' => 'same@gmail.com'])->assertOk();
        $this->assertNotNull($konsumen->fresh()->email_verified_at, 'same-address update must not wipe verification');
    }
}
