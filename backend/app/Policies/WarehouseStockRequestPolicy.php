<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseStockRequest;

class WarehouseStockRequestPolicy
{
    public function view(User $user, WarehouseStockRequest $request): bool
    {
        return in_array($user->role?->slug, ['admin', 'gudang'], true)
            && $request->agent_id === $user->agent_id;
    }

    public function create(User $user): bool
    {
        return $user->isRole('gudang') && $user->agent_id !== null;
    }

    public function approve(User $user, WarehouseStockRequest $request): bool
    {
        return $user->isRole('admin') && $request->agent_id === $user->agent_id;
    }

    public function reject(User $user, WarehouseStockRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
