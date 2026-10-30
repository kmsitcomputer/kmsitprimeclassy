<?php

namespace App\Services\WhatsApp;

use App\Models\User;

/**
 * Owner-ratified (2026-10-29): the number an ACCOUNT has proven through a `whatsapp_number` challenge may receive the
 * consent / CRM-link OTP, as an alternative to the verified email. Nothing else qualifies: not a number typed on a CRM
 * customer, not an unverified `users.phone`, not a number whose proof was voided by a phone change.
 */
final class VerifiedAccountNumberResolver implements VerifiedWhatsAppNumberResolver
{
    public function msisdnFor(User $subject): ?string
    {
        return WhatsAppNumberProof::msisdnFor($subject);
    }
}
