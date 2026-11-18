<?php

namespace App\Policies;

use App\Models\User;

class ProductCategoryPolicy
{
    public function manage(User $user): bool
    {
        return $user->isRole('super_admin');
    }

    /**
     * REM-06 (Owner 13 Nov 2026): the GLOBAL taxonomy is CREATE-ONLY for Admin Agen and Agen. Edit, delete
     * and reparent stay super_admin-only (see manage()); never widen those through this method.
     */
    public function create(User $user): bool
    {
        return $user->isRole('super_admin', 'admin', 'agen');
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user): bool
    {
        return $this->manage($user);
    }
}
