<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $transfer->agent_id === $user->agent_id);
    }

    public function create(User $user): bool
    {
        return $user->isRole('gudang') && $user->agent_id !== null;
    }

    public function approve(User $user, StockTransfer $transfer): bool
    {
        return $user->isRole('admin') && $transfer->agent_id === $user->agent_id;
    }

    public function reject(User $user, StockTransfer $transfer): bool
    {
        return $this->approve($user, $transfer);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $user->isRole('gudang') && $transfer->agent_id === $user->agent_id;
    }
}
