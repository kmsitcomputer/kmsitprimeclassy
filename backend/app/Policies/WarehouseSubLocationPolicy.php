<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseSubLocation;

class WarehouseSubLocationPolicy
{
    public function view(User $user, WarehouseSubLocation $location): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $location->agent_id === $user->agent_id);
    }

    public function create(User $user): bool
    {
        return $user->isRole('agen', 'admin') && $user->agent_id !== null;
    }

    public function update(User $user, WarehouseSubLocation $location): bool
    {
        return $user->isRole('agen', 'admin') && $location->agent_id === $user->agent_id;
    }
}
