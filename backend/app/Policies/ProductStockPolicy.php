<?php

namespace App\Policies;

use App\Models\ProductStock;
use App\Models\User;

class ProductStockPolicy
{
    public function view(User $user, ProductStock $stock): bool
    {
        return $user->isRole('super_admin')
            || ($user->isRole('agen', 'admin') && $stock->agent_id === $user->agent_id);
    }

    public function adjust(User $user, ProductStock $stock): bool
    {
        return $this->view($user, $stock);
    }
}
