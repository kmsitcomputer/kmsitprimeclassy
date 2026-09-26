<?php

namespace App\Policies;

use App\Models\StockRequestProposal;
use App\Models\User;

class StockRequestProposalPolicy
{
    public function view(User $user, StockRequestProposal $proposal): bool
    {
        return $user->isRole('super_admin') || ($user->isRole('agen', 'admin', 'gudang') && $proposal->agent_id === $user->agent_id);
    }

    public function propose(User $user): bool
    {
        return $user->isRole('gudang') && $user->agent_id !== null;
    }

    public function approve(User $user, StockRequestProposal $proposal): bool
    {
        return $user->isRole('admin') && $proposal->agent_id === $user->agent_id;
    }

    public function reject(User $user, StockRequestProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }
}
