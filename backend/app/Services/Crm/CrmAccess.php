<?php

namespace App\Services\Crm;

use App\Models\CrmCustomer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * IMP-006 authority (BUSINESS-RULES §27, OD-01). Write = the owning Sales/SKS — and, per the Phase 2 Owner rule
 * "Korsal has every functional Sales permission", the owning Korsal for its OWN customers (never a downline's). Read = owner,
 * their Korsal (downline), Agen and Admin of the same branch (read-only), super_admin. Read never
 * implies edit, reassignment or export. Everything is confined to the actor's own Agent.
 */
class CrmAccess
{
    public function canCreate(User $actor): bool
    {
        return $actor->isRole('sales', 'sales-kurir-sub', 'korsal') && $actor->agent_id !== null;
    }

    public function canWrite(User $actor, CrmCustomer $customer): bool
    {
        return $actor->isRole('sales', 'sales-kurir-sub', 'korsal')
            && $customer->owner_user_id === $actor->id
            && $customer->agent_id === $actor->agent_id;
    }

    /**
     * G17-D3 — Assisted Order: the owning Sales/SKS, or an upline that already has authority over that
     * seller's network in the SAME Agent: the owner's Korsal (`korsal_id`) and the branch Agen. Same
     * shape as OrderPolicy::create for a referred konsumen. Admin/keuangan/super_admin do not place
     * assisted orders. The order's commission attribution always stays on the owning Sales/SKS.
     */
    public function canPlaceOrder(User $actor, CrmCustomer $customer): bool
    {
        if ($customer->archived_at !== null || $actor->agent_id === null || $actor->agent_id !== $customer->agent_id) {
            return false;
        }

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            return $customer->owner_user_id === $actor->id;
        }

        if ($actor->isRole('agen')) {
            return true;
        }

        if ($actor->isRole('korsal')) {
            return $customer->owner_user_id === $actor->id || $customer->owner()->where('korsal_id', $actor->id)->exists();
        }

        return false;
    }

    /** Linking/OTP: only the owning seller drives it (CI-05: same Agent, enforced again in CrmLinkService). */
    public function canLink(User $actor, CrmCustomer $customer): bool
    {
        return $this->canWrite($actor, $customer);
    }

    /** Unlink (OD-04): the owning seller or the branch Agen/Admin, audited. Actor list still an Owner item. */
    public function canUnlink(User $actor, CrmCustomer $customer): bool
    {
        return $this->canWrite($actor, $customer)
            || ($actor->isRole('agen', 'admin') && $actor->agent_id !== null && $actor->agent_id === $customer->agent_id);
    }

    public function canRead(User $actor, CrmCustomer $customer): bool
    {
        if ($actor->isRole('super_admin')) {
            return true;
        }

        if ($customer->agent_id !== $actor->agent_id) {
            return false;
        }

        if ($actor->isRole('agen', 'admin')) {
            return true;
        }

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            return $customer->owner_user_id === $actor->id;
        }

        if ($actor->isRole('korsal')) {
            return $customer->owner_user_id === $actor->id || $customer->owner()->where('korsal_id', $actor->id)->exists();
        }

        return false;
    }

    /** @param  Builder<CrmCustomer>  $query */
    public function scopeVisible(Builder $query, User $actor): Builder
    {
        if ($actor->isRole('super_admin')) {
            return $query;
        }

        $query->where('crm_customers.agent_id', $actor->agent_id);

        if ($actor->isRole('agen', 'admin')) {
            return $query;
        }

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            return $query->where('crm_customers.owner_user_id', $actor->id);
        }

        if ($actor->isRole('korsal')) {
            return $query->where(fn ($q) => $q->where('crm_customers.owner_user_id', $actor->id)
                ->orWhereIn('crm_customers.owner_user_id', User::query()->where('korsal_id', $actor->id)->select('id')));
        }

        return $query->whereRaw('1 = 0');
    }
}
