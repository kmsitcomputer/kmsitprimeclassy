<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Consent\OtpChallengeService;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * WS-04 — self-service email verification for the authenticated account holder.
 *
 * Contact-change invalidation (no global `verified` middleware): this service only marks the CURRENT address
 * verified after the holder enters a code sent to that exact address. ProfileController already nulls
 * `email_verified_at` on every address change, so a stale verification can never cover a new address —
 * and the Google auto-link gate (`GoogleAuthService`, verified-email-only) inherits that safety for free.
 *
 * Reuses the hardened OTP challenge infra (HMAC hash, TTL, attempt limit, single-use, rate limits) via a new
 * `email_verification` purpose. The code travels by email only (MAIL_MAILER=log in testing; never in an API
 * response). WA delivery is deliberately NOT offered here: proving a number is WhatsAppNumberVerification's job.
 */
class EmailVerificationService
{
    public const PURPOSE = 'email_verification';

    public function __construct(
        private readonly OtpChallengeService $otp,
    ) {}

    /** @return array{email_masked:?string,verified:bool,pending:bool} */
    public function state(User $user): array
    {
        return [
            'email_masked' => $user->email === null ? null : $this->mask($user->email),
            'verified' => $user->email !== null && $user->email_verified_at !== null,
            'pending' => IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)->where('purpose', self::PURPOSE)
                ->whereNull('consumed_at')->whereNull('invalidated_at')->where('expires_at', '>', now())->exists(),
        ];
    }

    /**
     * Issue a verification code to the user's CURRENT email. Returns null when it is already verified (nothing sent).
     *
     * @return IdentityOtpChallenge|null null when the current address is already verified
     */
    public function request(User $user): ?IdentityOtpChallenge
    {
        if ($user->email === null) {
            throw new ApiException(__('messages.auth.email_verification_no_email'), 422);
        }

        if ($user->email_verified_at !== null) {
            return null;
        }

        $user = $user->fresh();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($user, $code) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            // Serialize issuance per subject so the rate limit cannot be raced.
            $recent = IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)
                ->where('purpose', self::PURPOSE)
                ->where('created_at', '>=', now()->subMinutes(OtpChallengeService::ISSUE_WINDOW_MINUTES))
                ->count();

            if ($recent >= OtpChallengeService::ISSUE_LIMIT) {
                throw new ApiException(__('messages.consent.otp_rate_limited'), 429);
            }

            IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $challenge = IdentityOtpChallenge::create([
                'agent_id' => $user->agent_id,
                'subject_user_id' => $user->id,
                'created_by' => $user->id,
                'purpose' => self::PURPOSE,
                'code_hash' => str_repeat('0', 64),
                // Column predates email-verification; holds the exact address being proven (masked log rule applies).
                'sent_to_email' => $user->email,
                'attempts' => 0,
                'max_attempts' => OtpChallengeService::MAX_ATTEMPTS,
                'expires_at' => now()->addMinutes(OtpChallengeService::TTL_MINUTES),
            ]);
            $challenge->forceFill(['code_hash' => $this->hash($challenge, $code)])->save();

            return $challenge;
        });

        Mail::to($user->email)->send(new \App\Mail\OtpChallengeMail($code, OtpChallengeService::TTL_MINUTES));

        return $challenge;
    }

    /** @return array{email_masked:?string,verified:bool,pending:bool} */
    public function confirm(User $user, int $challengeId, #[\SensitiveParameter] string $code): array
    {
        // 422 for anything that is not THIS user's own email-verification challenge: no probing of other accounts.
        $challenge = IdentityOtpChallenge::query()
            ->whereKey($challengeId)->where('subject_user_id', $user->id)
            ->where('purpose', self::PURPOSE)->first();
        if (! $challenge) {
            throw new ApiException(__('messages.consent.otp_invalid'), 422);
        }

        // Reuse the one shared verifier (HMAC, TTL, attempt limit, replay, single-use); self-issue means actor == subject.
        $this->otp->verify($user, $challenge, $code);

        DB::transaction(function () use ($user, $challenge) {
            $locked = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            if ($locked->consumed_at !== null || $locked->invalidated_at !== null || $locked->verified_at === null) {
                throw new ApiException(__('messages.consent.challenge_not_usable'), 422);
            }

            $fresh = $user->fresh();
            // The address may have changed between issue and confirm: the code proves the OLD address, not the new one.
            if ($fresh->email === null || ! hash_equals((string) $locked->sent_to_email, (string) $fresh->email)) {
                throw new ApiException(__('messages.auth.email_verification_stale'), 422);
            }
            if ($fresh->email_verified_at !== null) {
                throw new ApiException(__('messages.auth.email_already_verified'), 422);
            }

            $fresh->forceFill(['email_verified_at' => now()])->save();
            IdentityOtpChallenge::query()->whereKey($locked->id)->update(['consumed_at' => now(), 'invalidated_at' => null]);

            ActivityLogger::log($fresh->id, $fresh, 'auth.email_verified', null, [
                'email' => $this->mask($fresh->email),
            ]);
        });

        return $this->state($user->refresh());
    }

    private function hash(IdentityOtpChallenge $challenge, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$challenge->id, $challenge->subject_user_id, $challenge->purpose, $code]),
            (string) config('app.key'),
        );
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $head = mb_substr((string) $local, 0, 2);

        return $head.'••••@'.(string) $domain;
    }
}
