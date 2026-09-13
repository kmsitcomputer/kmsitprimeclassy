<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Catalog browsing is public — handled by routes without auth, not by
     * this policy. The catalog itself is shared/global across every agent
     * (products carry no agent_id; only ProductStock does) — agen was
     * deliberately given the same manage rights as super_admin here
     * (confirmed decision): any agen can create or edit any product, not
     * just ones they created.
     */
    public function manage(User $user): bool
    {
        return $user->isRole('super_admin', 'agen');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->manage($user);
    }

    /** Fee columns are only ever exposed to these roles — see ProductFeeResource. */
    public function viewFees(User $user): bool
    {
        return $user->isRole('super_admin', 'agen', 'sales');
    }
}
