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
        if (! $user->wasChanged('parent_id')) {
            return;
        }

        $this->hierarchy->reattachSubtree($user);
    }
}
