<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Mail\OtpChallengeMail;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Consent\OtpChallengeService;
use App\Services\Logging\ActivityLogger;
use App\Services\WhatsApp\WhatsAppNumberProof;
use App\Services\WhatsApp\WhatsAppOtpChannel;
use App\Support\PhoneNormalizer;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-04 — password recovery without account enumeration, reusing the hardened OTP challenge infra.
 *
 * Flow (unauthenticated; the identifier is email OR phone, mirroring login):
 *   1. POST /auth/forgot-password {identifier, channel?} -> ALWAYS the same generic answer, whether the account
 *      exists or not, whether it has a usable channel or not. No 404/422 may reveal which case applied.
 *      When the account exists AND the requested channel is reachable (verified email for `email`, proven number
 *      for `whatsapp`), a `password_recovery` challenge is issued and the code is sent through that channel.
 *   2. POST /auth/reset-password {identifier, code, password, password_confirmation} -> the code selects its
 *      challenge (scoped by subject + purpose + still-usable), and on success the password is replaced, all
 *      recovery challenges for that account are consumed, and Sanctum tokens are revoked (session theft containment).
 *
 * Design constraints (locked):
 *  - Email is OPTIONAL at registration and login accepts phone: both identifiers must work (channel email WA).
 *  - `OtpChallengeService::issue` cannot be reused directly: it requires an authenticated actor with capture
 *    authority and a verified email. Recovery runs pre-authentication, so issuance lives here with the same
 *    primitives (row lock, per-subject rate limit, invalidate-previous, HMAC hash, TTL, attempts, single use).
 *  - OtpChallengeService::verify cannot be reused either: it requires actor == created_by, but recovery has no
 *    actor. Verification here re-implements the same state machine (TTL / attempts / replay / single-use) plus
 *    the WA `channelStillValid` guard, bound to {subject, purpose, exact channel marker}.
 *  - Sync OTP delivery stays fail-closed (WhatsAppOtpChannel::deliver throws; email Mail::send throws): a code
 *    that cannot be delivered invalidates the challenge (`invalidated_at`), so a dead code can never be entered.
 *  - No global `verified` middleware and no new gate on login: recovery only ADDS a reset path, never a login block.
 */
class AccountRecoveryService
{
    public const PURPOSE = 'password_recovery';

    public function __construct(
        private readonly WhatsAppOtpChannel $whatsapp,
    ) {}

    /** Default response-time floor (ms). Override with config('auth.recovery_floor_ms'); tests set it to 0. */
    private const DEFAULT_FLOOR_MS = 600;

    /**
     * @return array{email_masked:?string,channel:string} masked routing hint only (never existence proof)
     *
     * Enumeration resistance (F-4/F-6) — what is and is not guaranteed:
     *  - The HTTP answer is byte-identical for every outcome (known/unknown account, unreachable channel, rate limit, transport
     *    failure, unexpected exception). No branch below may change the returned value.
     *  - An unknown identifier does decoy work of the same SHAPE as the real path's local part (one indexed challenge count,
     *    one random code, one HMAC) and is then padded to a response-time floor, as is every other outcome. A challenge is never
     *    issued for a nonexistent account.
     *  - NOT guaranteed: constant time. The real path calls SMTP / the WhatsApp gateway synchronously (fail-closed, by design);
     *    when that call is slower than the floor, an attacker with many samples may still distinguish it. The per-IP and
     *    per-identifier limiters (AppServiceProvider) bound how many samples are obtainable. Moving delivery to a queue would
     *    remove the gap but would also make delivery failure non-fail-closed, which is the safer property to keep.
     */
    public function request(string $identifier, ?string $channel = null): array
    {
        $startedAt = hrtime(true);
        $channel = $channel === 'whatsapp' ? 'whatsapp' : 'email';

        try {
            $user = $this->findByIdentifier($identifier);

            if ($user === null) {
                $this->decoy();
            } elseif ($channel === 'whatsapp') {
                $this->issueToWhatsApp($user);
            } else {
                $this->issueToEmail($user);
            }
        } catch (ApiException $e) {
            // Channel unreachable (unverified email, unproven number, gateway down, rate-limited): the public answer stays
            // identical. Only a coarse category is logged — never the identifier, the account or the exception text.
            Log::info('auth.recovery.not_issued', ['category' => match (true) {
                $e->status() === 429 => 'rate_limited',
                $e->status() >= 500 => 'delivery_failed',
                default => 'channel_unavailable',
            }]);
        } catch (\Throwable $e) {
            // F-6: any unexpected transport/DB failure is contained here and surfaces as the same generic answer. It is NOT
            // silent: it is logged as a warning (class only — messages can embed addresses/DSNs) for operators to act on.
            Log::warning('auth.recovery.unexpected_failure', ['category' => class_basename($e)]);
        }

        $this->padToFloor($startedAt);

        return ['email_masked' => null, 'channel' => $channel];
    }

    /** Same local cost shape as a real issuance (indexed read + CSPRNG + HMAC) without creating any challenge. */
    private function decoy(): void
    {
        IdentityOtpChallenge::query()
            ->where('subject_user_id', 0)->where('purpose', self::PURPOSE)
            ->where('created_at', '>=', now()->subMinutes(OtpChallengeService::ISSUE_WINDOW_MINUTES))
            ->count();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        hash_hmac('sha256', '0|0|'.self::PURPOSE.'|'.$code, (string) config('app.key'));
    }

    private function padToFloor(int $startedAt): void
    {
        $floorMs = (int) config('auth.recovery_floor_ms', self::DEFAULT_FLOOR_MS);
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if ($floorMs > 0 && $elapsedMs < $floorMs) {
            usleep(($floorMs - $elapsedMs + random_int(0, 100)) * 1000);
        }
    }

    public function reset(string $identifier, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): User
    {
        $user = $this->findByIdentifier($identifier);
        if ($user === null) {
            // Same error as a wrong code: existence is never revealed.
            throw new ApiException(__('messages.auth.recovery_invalid'), 422);
        }

        $verified = $this->verifyCode($user, $code);

        return DB::transaction(function () use ($user, $verified, $password) {
            // Re-read under lock: the challenge must still be usable at the exact moment the password changes.
            $locked = IdentityOtpChallenge::query()->whereKey($verified->id)->lockForUpdate()->firstOrFail();
            $subject = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->consumed_at !== null || $locked->invalidated_at !== null || $locked->verified_at === null) {
                throw new ApiException(__('messages.auth.recovery_invalid'), 422);
            }

            $subject->forceFill(['password' => Hash::make($password)])->save();

            // All recovery challenges for this account are spent: one code = one reset, replay impossible.
            IdentityOtpChallenge::query()
                ->where('subject_user_id', $subject->id)->where('purpose', self::PURPOSE)
                ->whereNull('consumed_at')->update(['consumed_at' => now(), 'invalidated_at' => null]);

            // Containment: tokens issued before the reset (possibly on a stolen session) stop working.
            $subject->tokens()->delete();

            ActivityLogger::log($subject->id, $subject, 'auth.password_reset', null, [
                'channel' => str_starts_with((string) $locked->sent_to_email, 'whatsapp:') ? 'whatsapp' : 'email',
            ]);

            return $subject;
        });
    }

    /** Login mirrors this resolution: email when it parses as email, otherwise normalised phone. */
    private function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->first();
        }

        $phone = PhoneNormalizer::normalize($identifier);

        return User::query()->where('phone', $phone)->first();
    }

    private function issueToEmail(User $user): void
    {
        if ($user->email === null || $user->email_verified_at === null) {
            throw new ApiException(__('messages.consent.email_not_verified'), 422);
        }

        $email = $user->email;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = $this->newChallenge($user, $email, OtpChallengeService::TTL_MINUTES, OtpChallengeService::MAX_ATTEMPTS, $code);

        try {
            Mail::to($email)->send(new OtpChallengeMail($code, OtpChallengeService::TTL_MINUTES));
        } catch (\Throwable $e) {
            $challenge->forceFill(['invalidated_at' => now()])->save();
            throw new ApiException(__('messages.whatsapp.otp_not_delivered'), 502);
        }
    }

    private function issueToWhatsApp(User $user): void
    {
        ['policy' => $policy, 'msisdn' => $msisdn] = $this->whatsapp->prepare($user);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = $this->newChallenge($user, 'whatsapp:'.WhatsAppNumber::mask($msisdn), $policy['ttl'], $policy['max'], $code);

        // Fail-closed: deliver() invalidates the challenge itself when the message is not provably SENT.
        $this->whatsapp->deliver($challenge, $user, $msisdn, $code, $policy['ttl']);
    }

    private function newChallenge(User $user, string $marker, int $ttl, int $maxAttempts, #[\SensitiveParameter] string $code): IdentityOtpChallenge
    {
        return DB::transaction(function () use ($user, $marker, $ttl, $maxAttempts, $code) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

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
                // No actor pre-authentication: the subject owns its own recovery challenge.
                'created_by' => $user->id,
                'purpose' => self::PURPOSE,
                'code_hash' => str_repeat('0', 64),
                'sent_to_email' => $marker,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'expires_at' => now()->addMinutes($ttl),
            ]);
            $challenge->forceFill(['code_hash' => $this->hash($challenge, $code)])->save();

            return $challenge;
        });
    }

    /** Same state machine as OtpChallengeService::verify, minus the actor binding (no actor exists pre-auth). */
    private function verifyCode(User $user, #[\SensitiveParameter] string $code): IdentityOtpChallenge
    {
        $outcome = DB::transaction(function () use ($user, $code) {
            // The code identifies its challenge: latest still-usable recovery challenge for this subject only.
            // (Scoping by subject first means a code from account A can never be tried against account B.)
            $candidate = IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)->where('purpose', self::PURPOSE)
                ->whereNull('consumed_at')->whereNull('invalidated_at')
                ->orderByDesc('id')->lockForUpdate()->first();

            if ($candidate === null) {
                return ['invalid', null];
            }
            if ($candidate->expires_at->isPast()) {
                return ['expired', null];
            }
            if (! WhatsAppNumberProof::channelStillValid($candidate, $user)) {
                // A WA code whose proof died (phone changed) is dead too — same rule as consent OTP.
                return ['invalid', null];
            }
            if ($candidate->attempts >= $candidate->max_attempts) {
                return ['locked', null];
            }

            $candidate->increment('attempts');

            if (! hash_equals($candidate->code_hash, $this->hash($candidate, $code))) {
                return ['mismatch', null];
            }

            $candidate->forceFill(['verified_at' => now()])->save();

            return ['ok', $candidate];
        });

        return match ($outcome[0]) {
            'ok' => $outcome[1],
            'locked' => throw new ApiException(__('messages.consent.otp_locked'), 429),
            'expired' => throw new ApiException(__('messages.auth.recovery_expired'), 422),
            default => throw new ApiException(__('messages.auth.recovery_invalid'), 422),
        };
    }

    private function hash(IdentityOtpChallenge $challenge, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$challenge->id, $challenge->subject_user_id, $challenge->purpose, $code]),
            (string) config('app.key'),
        );
    }
}
