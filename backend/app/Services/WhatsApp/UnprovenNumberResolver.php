<?php

namespace App\Services\WhatsApp;

use App\Models\User;

/** Default: no number is proven (see the interface). Deliberately returns null for everyone. */
final class UnprovenNumberResolver implements VerifiedWhatsAppNumberResolver
{
    public function msisdnFor(User $subject): ?string
    {
        return null;
    }
}
