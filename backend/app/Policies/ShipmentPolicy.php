<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    /**
     * The kurir-facing counterpart of OrderPolicy::updateStatus/assignCourier
     * — a single order can now span several shipments (one per courier, once
     * a rescheduled item splits off — see the migration docblock on
     * order_items.shipment_id), so kurir act per-shipment instead of
     * order-wide. WHICH specific transition and WHOSE delivery is enforced in
     * CourierService::updateShipmentStatus, not here.
     */
    public function updateStatus(User $user, Shipment $shipment): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('agen', 'admin', 'kurir') && $shipment->order?->agent_id === $user->agent_id;
    }

    /** Proactively assigning a courier to a shipment is an office decision — a kurir self-assigns instead, via updateStatus. */
    public function assignCourier(User $user, Shipment $shipment): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('agen', 'admin') && $shipment->order?->agent_id === $user->agent_id;
    }
}
