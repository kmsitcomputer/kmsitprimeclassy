<?php

namespace App\Policies;

use App\Models\StockRequest;
use App\Models\User;

class StockRequestPolicy
{
    public function view(User $user, StockRequest $request): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $request->agent_id === $user->agent_id);
    }

    public function fulfill(User $user, StockRequest $request): bool
    {
        return $user->isRole('gudang') && $request->agent_id === $user->agent_id;
    }
}
