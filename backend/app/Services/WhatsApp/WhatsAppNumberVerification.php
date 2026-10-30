<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Consent\OtpChallengeService;
use App\Services\Logging\ActivityLogger;
use App\Support\WhatsAppNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Self-service proof of control of a WhatsApp number (the account holder only):
 *   1. request(number)  -> OTP challenge bound to {account, purpose whatsapp_number, that exact number, expiry}; code sent by GOWA
 *   2. confirm(code)    -> OtpChallengeService::verify (attempts/expiry/replay) -> the challenge is CONSUMED = proof
 * The number is only written to `users.phone` AFTER the correct code arrived, and a phone change voids earlier proofs.
 * It never creates consent, never links a CRM customer, never touches orders/commissions.
 */
class WhatsAppNumberVerification
{
    public function __construct(
        private readonly OtpChallengeService $otp,
        private readonly WhatsAppOtpChannel $channel,
    ) {}

    /** @return array{has_phone:bool, phone_masked:?string, verified:bool, channel_available:bool, pending:bool} */
    public function state(User $user): array
    {
        $msisdn = WhatsAppNumber::msisdn($user->phone);

        return [
            'has_phone' => $msisdn !== null,
            'phone_masked' => WhatsAppNumber::mask($msisdn),
            'verified' => WhatsAppNumberProof::msisdnFor($user) !== null,
            'channel_available' => $this->channel->available($user),
            'pending' => IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)->where('purpose', OtpChallengeService::PURPOSE_WHATSAPP_NUMBER)
                ->whereNull('consumed_at')->whereNull('invalidated_at')->where('expires_at', '>', now())->exists(),
        ];
    }

    /**
     * @param  string|null  $phone  a NEW number to prove (replaces the account phone only after the code is confirmed);
     *                              null = prove the number already on the account
     * @return IdentityOtpChallenge|null null when that exact number is already proven (nothing is sent)
     */
    public function request(User $user, ?string $phone): ?IdentityOtpChallenge
    {
        $target = WhatsAppNumber::msisdn($phone !== null && $phone !== '' ? $phone : $user->phone);
        if ($target === null) {
            throw new ApiException(__('messages.whatsapp.invalid_number'), 422, ['phone' => [__('messages.whatsapp.invalid_number')]]);
        }

        if (WhatsAppNumberProof::msisdnFor($user) === $target) {
            return null;
        }

        return $this->otp->issueNumberVerification($user, $target);
    }

    /** @return array{has_phone:bool, phone_masked:?string, verified:bool, channel_available:bool, pending:bool} */
    public function confirm(User $user, int $challengeId, #[\SensitiveParameter] string $code): array
    {
        // 404 for anything that is not THIS user's own number challenge: no probing of other accounts' challenges.
        $challenge = IdentityOtpChallenge::query()
            ->whereKey($challengeId)->where('subject_user_id', $user->id)
            ->where('purpose', OtpChallengeService::PURPOSE_WHATSAPP_NUMBER)->first();
        if (! $challenge) {
            throw new ApiException(__('messages.consent.otp_invalid'), 422);
        }

        // Attempt limit / expiry / replay / actor binding live in the one shared verifier.
        $this->otp->verify($user, $challenge, $code);

        DB::transaction(function () use ($user, $challenge) {
            $locked = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            if ($locked->consumed_at !== null || $locked->invalidated_at !== null || $locked->verified_at === null) {
                throw new ApiException(__('messages.consent.challenge_not_usable'), 422);
            }

            $msisdn = substr((string) $locked->sent_to_email, 3);
            $changed = WhatsAppNumber::msisdn($user->phone) !== $msisdn;

            if ($changed) {
                try {
                    $user->phone = $msisdn; // the model normalises it (62812… -> 0812…)
                    $user->save();
                } catch (QueryException $e) {
                    // The unique phone index belongs to another account. Say nothing that reveals whose.
                    throw new ApiException(__('messages.whatsapp.number_unavailable'), 422);
                }
            }

            // Saving a changed phone voided every earlier proof — including this challenge — through a bulk UPDATE the in-memory
            // model knows nothing about. Re-seal exactly this one with an explicit UPDATE (a forceFill() would see no dirty field).
            IdentityOtpChallenge::query()->whereKey($locked->id)->update(['consumed_at' => now(), 'invalidated_at' => null]);

            ActivityLogger::log($user->id, $user, 'whatsapp.number.verified', null, [
                'number' => WhatsAppNumber::mask($msisdn), 'phone_changed' => $changed,
            ]);
        });

        return $this->state($user->refresh());
    }
}
