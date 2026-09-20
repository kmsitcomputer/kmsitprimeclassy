<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

class StockOpnamePolicy
{
    public function view(User $user, StockOpname $opname): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $opname->agent_id === $user->agent_id);
    }

    public function create(User $user): bool
    {
        return $user->isRole('gudang') && $user->agent_id !== null;
    }

    public function edit(User $user, StockOpname $opname): bool
    {
        return $user->isRole('gudang') && $opname->agent_id === $user->agent_id;
    }

    public function approve(User $user, StockOpname $opname): bool
    {
        return $user->isRole('admin') && $opname->agent_id === $user->agent_id;
    }

    public function reject(User $user, StockOpname $opname): bool
    {
        return $this->approve($user, $opname);
    }

    public function cancel(User $user, StockOpname $opname): bool
    {
        return $this->edit($user, $opname);
    }
}
