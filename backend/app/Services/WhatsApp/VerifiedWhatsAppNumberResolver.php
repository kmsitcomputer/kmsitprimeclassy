<?php

namespace App\Services\WhatsApp;

use App\Models\User;

/**
 * "Which WhatsApp number of this person has been PROVEN to be theirs?" — the gate in front of WhatsApp OTP.
 *
 * Owner rule (2026-10-31, still in force): the verified ACCOUNT email stays the only accepted OTP channel until a secure
 * number-ownership proof is ratified. A number typed by a Sales on a CRM customer, or sitting unverified in `users.phone`,
 * is NEVER treated as verified. No such proof mechanism exists yet, so the default binding resolves nothing and every
 * WhatsApp-OTP request is blocked with `whatsapp_number_not_verified`. A future, Owner-approved proof (for example the
 * authenticated account holder confirming the number through a code sent to that very number) would bind another
 * implementation here without touching the OTP flow.
 */
interface VerifiedWhatsAppNumberResolver
{
    /** msisdn (digits, country code) proven to belong to `$subject`, or null. */
    public function msisdnFor(User $subject): ?string;
}
