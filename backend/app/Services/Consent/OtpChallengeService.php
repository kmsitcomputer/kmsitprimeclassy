<?php

namespace App\Services\Consent;

use App\Exceptions\ApiException;
use App\Mail\OtpChallengeMail;
use App\Models\CrmCustomer;
use App\Models\IdentityOtpChallenge;
use App\Services\Crm\CrmAccess;
use App\Services\WhatsApp\WhatsAppOtpChannel;
use App\Support\WhatsAppNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * REM-005 / CI-01: verified-email channel + single-use OTP. The code is delivered by email
 * only (never in an API response), stored as an HMAC, expires, is attempt-limited and bound to
 * subject + purpose + issuing actor. Email/phone equality alone never satisfies it.
 */
class OtpChallengeService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const ISSUE_LIMIT = 3;

    public const ISSUE_WINDOW_MINUTES = 10;

    public const PURPOSE_CRM_LINK = 'crm_link';

    public function __construct(
        private readonly ConsentAccess $access,
        private readonly CrmAccess $crmAccess,
        private readonly WhatsAppOtpChannel $whatsapp,
    ) {}

    /**
     * @param  CrmCustomer|null  $crmCustomer  when set the challenge is a CRM-link OTP bound to that customer (CI-01):
     *                                          the actor must own it and be allowed to reach the target account.
     */
    public function issue(User $actor, User $subject, string $purpose, ?CrmCustomer $crmCustomer = null, string $channel = 'email'): IdentityOtpChallenge
    {
        if ($crmCustomer !== null && $purpose === ConsentService::PURPOSES[0]) {
            // Owner decision 2026-10-31: consent for a CRM customer needs an OTP on a VERIFIED customer channel. The only
            // such channel is the verified email of the konsumen account this customer is LINKED to (OD-03). Nothing else.
            if (! $this->access->canCaptureCrm($actor, $crmCustomer)
                || $crmCustomer->agent_id !== $subject->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 403);
            }
            if ($crmCustomer->linked_konsumen_id === null || $crmCustomer->linked_konsumen_id !== $subject->id) {
                throw new ApiException(__('messages.crm.consent_channel_unavailable'), 409);
            }
        } elseif ($crmCustomer !== null) {
            if ($purpose !== self::PURPOSE_CRM_LINK
                || ! $this->crmAccess->canLink($actor, $crmCustomer)
                || $crmCustomer->agent_id !== $subject->agent_id
                || ! $this->access->canCapture($actor, $subject)) {
                // Identical answer for "not yours to reach" and "no such account" (see CrmAssistedController::linkOtp).
                throw new ApiException(__('messages.crm.link_not_allowed'), 403);
            }
        } elseif (! $this->access->canCapture($actor, $subject)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $policy = ['ttl' => self::TTL_MINUTES, 'max' => self::MAX_ATTEMPTS, 'limit' => self::ISSUE_LIMIT, 'window' => self::ISSUE_WINDOW_MINUTES, 'cooldown' => 0];
        $msisdn = null;

        if ($channel === 'whatsapp') {
            // WhatsApp is only a DELIVERY channel for the same challenge, and only for a number whose ownership has been PROVEN.
            // Delivery success never equals verification: the challenge is satisfied only by entering the code.
            ['policy' => $policy, 'msisdn' => $msisdn] = $this->whatsapp->prepare($subject);
        } elseif (! $subject->email || ! $subject->email_verified_at) {
            throw new ApiException(__('messages.consent.email_not_verified'), 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($actor, $subject, $purpose, $code, $crmCustomer, $policy, $msisdn) {
            // Serialize issuance per subject so the rate limit cannot be raced.
            User::query()->whereKey($subject->id)->lockForUpdate()->first();

            $recent = IdentityOtpChallenge::query()
                ->where('subject_user_id', $subject->id)
                ->where('purpose', $purpose)
                ->where('created_at', '>=', now()->subMinutes($policy['window']))
                ->count();

            if ($recent >= $policy['limit']) {
                throw new ApiException(__('messages.consent.otp_rate_limited'), 429);
            }

            if ($policy['cooldown'] > 0 && IdentityOtpChallenge::query()
                ->where('subject_user_id', $subject->id)->where('purpose', $purpose)
                ->where('created_at', '>', now()->subSeconds($policy['cooldown']))->exists()) {
                throw new ApiException(__('messages.whatsapp.otp_cooldown', ['seconds' => $policy['cooldown']]), 429);
            }

            IdentityOtpChallenge::query()
                ->where('subject_user_id', $subject->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $challenge = IdentityOtpChallenge::create([
                'agent_id' => $subject->agent_id,
                'subject_user_id' => $subject->id,
                ...($crmCustomer ? ['crm_customer_id' => $crmCustomer->id] : []),
                'created_by' => $actor->id,
                'purpose' => $purpose,
                'code_hash' => str_repeat('0', 64),
                // Column predates WhatsApp; for that channel it holds a masked marker, never a full number.
                'sent_to_email' => $msisdn !== null ? 'whatsapp:'.WhatsAppNumber::mask($msisdn) : $subject->email,
                'attempts' => 0,
                'max_attempts' => $policy['max'],
                'expires_at' => now()->addMinutes($policy['ttl']),
            ]);
            $challenge->forceFill(['code_hash' => $this->hash($challenge, $code)])->save();

            return $challenge;
        });

        if ($msisdn !== null) {
            $this->whatsapp->deliver($challenge, $subject, $msisdn, $code, $policy['ttl']);
        } else {
            Mail::to($subject->email)->send(new OtpChallengeMail($code, self::TTL_MINUTES));
        }

        return $challenge;
    }

    public const PURPOSE_WHATSAPP_NUMBER = 'whatsapp_number';

    /**
     * Proof of control of a WhatsApp number (NOT a consent): the account holder asks for a code to be sent to `$msisdn`
     * and must type it back. Same challenge rules as every other OTP (HMAC hash, TTL, attempt limit, single use, bound to
     * subject + purpose + number), plus limits per account AND per number. Delivery success proves nothing; only the
     * correct code does (see WhatsAppNumberVerification::confirm).
     */
    public function issueNumberVerification(User $user, string $msisdn): IdentityOtpChallenge
    {
        $policy = $this->whatsapp->numberVerificationPolicy($user);
        $marker = 'wa:'.$msisdn;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($user, $marker, $policy, $code) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $since = now()->subMinutes($policy['window']);

            // Per ACCOUNT.
            $mine = IdentityOtpChallenge::query()->where('subject_user_id', $user->id)->where('purpose', self::PURPOSE_WHATSAPP_NUMBER);
            if ((clone $mine)->where('created_at', '>=', $since)->count() >= $policy['limit']) {
                throw new ApiException(__('messages.whatsapp.verification_rate_limited'), 429);
            }
            if ($policy['cooldown'] > 0 && (clone $mine)->where('created_at', '>', now()->subSeconds($policy['cooldown']))->exists()) {
                throw new ApiException(__('messages.whatsapp.otp_cooldown', ['seconds' => $policy['cooldown']]), 429);
            }
            // Per NUMBER, across every account: nobody can be spammed by many accounts pointing at one number.
            if (IdentityOtpChallenge::query()->where('purpose', self::PURPOSE_WHATSAPP_NUMBER)->where('sent_to_email', $marker)
                ->where('created_at', '>=', $since)->count() >= $policy['limit']) {
                throw new ApiException(__('messages.whatsapp.verification_rate_limited'), 429);
            }

            (clone $mine)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            $challenge = IdentityOtpChallenge::create([
                'agent_id' => $user->agent_id ?? $user->id,
                'subject_user_id' => $user->id,
                'created_by' => $user->id,
                'purpose' => self::PURPOSE_WHATSAPP_NUMBER,
                'code_hash' => str_repeat('0', 64),
                // The column predates WhatsApp; for this purpose it holds the number being proven (== users.phone's msisdn).
                'sent_to_email' => $marker,
                'attempts' => 0,
                'max_attempts' => $policy['max'],
                'expires_at' => now()->addMinutes($policy['ttl']),
            ]);
            $challenge->forceFill(['code_hash' => $this->hash($challenge, $code)])->save();

            return $challenge;
        });

        $this->whatsapp->deliver($challenge, $user, $msisdn, $code, $policy['ttl']);

        return $challenge;
    }
    public function verify(User $actor, IdentityOtpChallenge $challenge, #[\SensitiveParameter] string $code): IdentityOtpChallenge
    {
        if ($challenge->created_by !== $actor->id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        // Attempts must persist even when the code is wrong, so the failure is thrown after commit.
        $outcome = DB::transaction(function () use ($challenge, $code) {
            $locked = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

            if ($locked->consumed_at || $locked->invalidated_at || $locked->verified_at) {
                return ['invalid', null];
            }
            if ($locked->expires_at->isPast()) {
                return ['expired', null];
            }
            if (! \App\Services\WhatsApp\WhatsAppNumberProof::channelStillValid($locked, User::query()->find($locked->subject_user_id))
                && $locked->purpose !== self::PURPOSE_WHATSAPP_NUMBER) {
                return ['invalid', null];
            }
            if ($locked->attempts >= $locked->max_attempts) {
                return ['locked', null];
            }

            $locked->increment('attempts');

            if (! hash_equals($locked->code_hash, $this->hash($locked, $code))) {
                return ['mismatch', null];
            }

            $locked->forceFill(['verified_at' => now()])->save();

            return ['ok', $locked];
        });

        return match ($outcome[0]) {
            'ok' => $outcome[1],
            'locked' => throw new ApiException(__('messages.consent.otp_locked'), 429),
            'expired' => throw new ApiException(__('messages.consent.otp_expired'), 422),
            default => throw new ApiException(__('messages.consent.otp_invalid'), 422),
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
