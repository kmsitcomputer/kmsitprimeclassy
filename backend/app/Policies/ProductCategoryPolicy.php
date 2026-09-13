<?php

namespace App\Policies;

use App\Models\User;

class ProductCategoryPolicy
{
    public function manage(User $user): bool
    {
        return $user->isRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
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
