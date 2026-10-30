<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Hierarchy\HierarchyService;

class UserObserver
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function created(User $user): void
    {
        $this->hierarchy->attachClosures($user);
    }

    /**
     * A user's upline changing is a rare, deliberate business action (see
     * Blueprint §Referral "Aturan integritas") — when it happens, the closure
     * rows for this user and everyone below them must be rebuilt, not just
     * patched, since depth for the whole subtree shifts.
     */
    public function updated(User $user): void
    {
        // WhatsApp number proof belongs to ONE number: any change of `phone` invalidates every earlier proof, so switching
        // back to an old number later requires a fresh OTP (a consumed challenge can never be "revived").
        if ($user->wasChanged('phone')) {
            \App\Models\IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)->where('purpose', 'whatsapp_number')->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            // A consent / CRM-link code still in flight to the OLD number must not be usable any more either.
            \App\Models\IdentityOtpChallenge::query()
                ->where('subject_user_id', $user->id)->where('sent_to_email', 'like', 'whatsapp:%')
                ->whereNull('consumed_at')->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);
        }

        if (! $user->wasChanged('parent_id')) {
            return;
        }

        $this->hierarchy->reattachSubtree($user);
    }
}
