<?php

namespace App\Policies;

use App\Models\SubStockRequest;
use App\Models\User;

class SubStockRequestPolicy
{
    public function view(User $user, SubStockRequest $request): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }
        if ($user->isRole('sales-kurir-sub')) {
            return $request->requested_by === $user->id;
        }

        return $user->isRole('agen', 'admin', 'gudang') && $request->agent_id === $user->agent_id;
    }
}
