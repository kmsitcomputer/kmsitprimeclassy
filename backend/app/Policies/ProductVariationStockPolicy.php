<?php

namespace App\Policies;

use App\Models\ProductVariationStock;
use App\Models\User;

class ProductVariationStockPolicy
{
    public function view(User $user, ProductVariationStock $stock): bool
    {
        return $user->isRole('super_admin')
            || ($user->isRole('agen', 'admin') && $stock->agent_id === $user->agent_id);
    }

    public function adjust(User $user, ProductVariationStock $stock): bool
    {
        return $this->view($user, $stock);
    }
}
