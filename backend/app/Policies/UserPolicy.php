<?php

namespace App\Policies;

use App\Models\User;
use App\Support\HierarchyRules;

class UserPolicy
{
    /** Can $user create an account with role $targetRoleSlug? See HierarchyRules::ALLOWED_CREATIONS. */
    public function create(User $user, string $targetRoleSlug): bool
    {
        return HierarchyRules::canCreate($user->role?->slug ?? '', $targetRoleSlug);
    }

    /** Can $user view $target's profile/downline data? */
    public function view(User $user, User $target): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($user->id === $target->id) {
            return true;
        }

        if ($user->isRole('agen', 'admin', 'keuangan')) {
            return $target->agent_id === $user->agent_id;
        }

        if ($user->isRole('korsal')) {
            return $target->korsal_id === $user->id || $target->id === $user->id;
        }

        if ($user->isRole('sales')) {
            return $target->sales_id === $user->id;
        }

        return false;
    }

    /**
     * Editing basic profile fields (name/phone/status) — same reach as
     * view() for account managers. KEUANGAN is deliberately excluded: it
     * participates in the branch (can view network users) but is not an
     * account manager, so it may only ever edit its own profile (which goes
     * through ProfileController, not this endpoint). Never role_id/agent_id/
     * hierarchy fields (that's a re-parent operation, not an edit).
     */
    public function update(User $user, User $target): bool
    {
        if ($user->isRole('keuangan')) {
            return $user->id === $target->id;
        }

        return $this->view($user, $target);
    }

    /**
     * Deleting a downline account is destructive enough (orphans referral
     * chains, hierarchy, historical order/commission attribution) to keep
     * un-delegated for most roles — super_admin always may (except itself,
     * see UserController::destroy for the "never the last super_admin"
     * rule). The one delegated exception: an agen may delete an
     * admin/keuangan/kurir it created within its own branch, mirroring the
     * create permission it already has for those roles — korsal/sales still
     * can never delete anyone, and an agen can never delete another agen or
     * another agent's staff.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($user->isRole('agen') && in_array($target->role?->slug, ['admin', 'keuangan', 'kurir'], true)) {
            return $target->agent_id === $user->agent_id;
        }

        return false;
    }
}
