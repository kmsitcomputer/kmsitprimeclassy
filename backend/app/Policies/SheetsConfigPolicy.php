<?php

namespace App\Policies;

use App\Models\SheetsConfig;
use App\Models\User;

class SheetsConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin') && $user->agent_id !== null);
    }

    public function manage(User $user, SheetsConfig $config): bool
    {
        if ($user->isRole('admin') && $config->dataset === 'financial_summary') {
            return false;
        }

        return $this->viewAny($user) && ($user->isRole('super_admin') || $config->destination->agent_id === $user->agent_id);
    }
}
