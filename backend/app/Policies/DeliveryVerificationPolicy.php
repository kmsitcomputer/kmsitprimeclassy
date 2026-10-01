<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

/**
 * R-03 / decision B: Admin final delivery verification authority.
 *
 * Admin only, same-Agent. Super Admin follows the existing explicit application policy pattern
 * (global override) rather than an invented broader transition privilege.
 */
class DeliveryVerificationPolicy
{
    public function view(User $user, Shipment $shipment): bool
    {
        return $this->create($user, $shipment);
    }

    public function create(User $user, Shipment $shipment): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('admin') && (int) ($shipment->order?->agent_id) === (int) $user->agent_id;
    }
}
