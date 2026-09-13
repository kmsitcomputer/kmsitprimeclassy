<?php

namespace App\Policies;

use App\Models\Commission;
use App\Models\User;

class CommissionPolicy
{
    /**
     * `commissions` has no agent_id column of its own (see database.sql), so
     * unlike Order/ProductStock this is NOT covered by BelongsToAgentScope —
     * this Policy is the only enforcement point and must be checked on every
     * access path to this model.
     */
    public function view(User $user, Commission $commission): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($commission->beneficiary_user_id === $user->id) {
            return true;
        }

        if ($user->isRole('agen', 'admin')) {
            return $commission->beneficiary?->agent_id === $user->agent_id;
        }

        return false;
    }
}
