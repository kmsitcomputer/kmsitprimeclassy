<?php

namespace App\Policies;

use App\Models\StockHandover;
use App\Models\User;

class StockHandoverPolicy
{
    public function view(User $user, StockHandover $handover): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $handover->agent_id === $user->agent_id);
    }

    public function print(User $user, StockHandover $handover): bool
    {
        return $this->view($user, $handover);
    }
}
