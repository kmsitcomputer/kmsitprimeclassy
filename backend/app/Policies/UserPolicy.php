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

    public function convertToSalesKurir(User $user, User $target): bool
    {
        return $user->isRole('agen')
            && $user->agent_id !== null
            && $target->agent_id === $user->agent_id;
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

        if ($user->isRole('sales', 'sales-kurir')) {
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
     * chains, hierarchy, historical order/commission attribution) that only
     * two roles may do it. super_admin always may (except itself and other
     * super_admins/agens, see UserController::destroy for the "never the last
     * super_admin" rule). An agen may delete EVERY role beneath it within its
     * own branch (korsal, sales, sales-kurir, admin, keuangan, kurir, gudang,
     * konsumen) — but never another agen, a super_admin, itself, or anyone in
     * another agent's branch. korsal/sales can never delete anyone.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if ($user->isRole('super_admin')) {
            return $target->role?->slug !== 'super_admin' && $target->role?->slug !== 'agen';
        }

        if ($user->isRole('agen') && $user->agent_id !== null) {
            return ! in_array($target->role?->slug, ['super_admin', 'agen'], true)
                && $target->agent_id === $user->agent_id;
        }

        return false;
    }
}
