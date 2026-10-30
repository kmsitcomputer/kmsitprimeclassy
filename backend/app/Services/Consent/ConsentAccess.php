<?php

namespace App\Services\Consent;

use App\Models\CrmCustomer;
use App\Models\User;

/**
 * REM-005 authority for acting on a konsumen's consent/identity (CI-03, CI-05).
 * Capturing roles act only inside their own Agent and referral chain; review roles act only
 * inside their own Agent. Nothing here grants return approval, inspection or refund authority.
 */
class ConsentAccess
{
    /** Sales / Sales-Kurir-Sub (own referral) or Agen (own branch) may capture consent for a konsumen. */
    public function canCapture(User $actor, User $subject): bool
    {
        if (! $subject->isRole('konsumen') || $subject->agent_id === null || $actor->agent_id !== $subject->agent_id) {
            return false;
        }

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            return $subject->sales_id === $actor->id;
        }

        // Korsal acting as Sales: its OWN referral only — a konsumen whose Sales-fee recipient is the Korsal
        // (OrderService::priceAndReserveLine: sales_id ?? korsal_id). A downline Sales' konsumen is NOT reachable.
        if ($actor->isRole('korsal')) {
            return $subject->sales_id === $actor->id || ($subject->sales_id === null && $subject->korsal_id === $actor->id);
        }

        return $actor->isRole('agen');
    }

    /** CRM-only customer: only the owning Sales/SKS (same Agent) may capture consent (CI-03). */
    public function canCaptureCrm(User $actor, CrmCustomer $customer): bool
    {
        return $actor->isRole('sales', 'sales-kurir-sub', 'korsal')
            && $customer->owner_user_id === $actor->id
            && $actor->agent_id !== null
            && $actor->agent_id === $customer->agent_id;
    }

    public function canReviewCrm(User $actor, CrmCustomer $customer): bool
    {
        return $actor->isRole('super_admin')
            || ($actor->isRole('agen', 'admin') && $actor->agent_id !== null && $actor->agent_id === $customer->agent_id);
    }

    /** Authorized review (dispute handling / reinstatement): same-Agent agen/admin, or super_admin. */
    public function canReview(User $actor, User $subject): bool
    {
        if ($actor->isRole('super_admin')) {
            return true;
        }

        return $actor->isRole('agen', 'admin')
            && $subject->agent_id !== null
            && $actor->agent_id === $subject->agent_id;
    }

    /** The customer themself, a capturing actor in scope, or a reviewer may withdraw/dispute. */
    public function canWithdrawOrDispute(User $actor, User $subject): bool
    {
        return $actor->id === $subject->id
            || $this->canCapture($actor, $subject)
            || $this->canReview($actor, $subject);
    }
}
