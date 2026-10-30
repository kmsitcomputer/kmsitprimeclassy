<?php

namespace App\Services\WhatsApp;

use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Consent\OtpChallengeService;
use App\Support\WhatsAppNumber;

/**
 * "Has this account PROVEN control of the WhatsApp number it currently has?" — derived, never stored as a flag:
 *
 *   an OTP challenge of purpose `whatsapp_number` that was CONSUMED (the correct code was entered), is NOT invalidated,
 *   and was issued for exactly the msisdn of `users.phone` right now.
 *
 * Consequences by construction: changing the phone voids the proof (the number no longer matches, and UserObserver also
 * invalidates the old rows so reverting to an old number cannot revive it); a failed/expired/unconfirmed challenge proves
 * nothing; "message delivered" is irrelevant. No dependencies on purpose: it is read on every order notification.
 *
 * This proves control of the CHANNEL only. It is not consent for an order/return and it does not link a CRM customer.
 */
final class WhatsAppNumberProof
{
    /**
     * May this challenge still be satisfied/consumed? An email challenge: always (its own rules apply). A challenge whose code
     * went to WhatsApp: only while the subject STILL holds a valid proof for the very number the code was sent to. A phone change
     * or a voided proof therefore kills a code that is still in flight.
     */
    public static function channelStillValid(IdentityOtpChallenge $challenge, ?User $subject): bool
    {
        $marker = (string) $challenge->sent_to_email;
        if (! str_starts_with($marker, 'whatsapp:')) {
            return true;
        }

        // Same source of truth as the gate that allowed the code to be sent (the bound resolver), never a second opinion.
        $msisdn = $subject ? app(VerifiedWhatsAppNumberResolver::class)->msisdnFor($subject) : null;

        return $msisdn !== null && hash_equals('whatsapp:'.(string) WhatsAppNumber::mask($msisdn), $marker);
    }

    public static function msisdnFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $msisdn = WhatsAppNumber::msisdn($user->phone);
        if ($msisdn === null) {
            return null;
        }

        $proven = IdentityOtpChallenge::query()
            ->where('subject_user_id', $user->id)
            ->where('purpose', OtpChallengeService::PURPOSE_WHATSAPP_NUMBER)
            ->where('sent_to_email', 'wa:'.$msisdn)
            ->whereNotNull('consumed_at')
            ->whereNull('invalidated_at')
            ->exists();

        return $proven ? $msisdn : null;
    }
}
