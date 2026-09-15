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

    /**
     * Thermal shipping-receipt print (pre-pickup / post-pickup / reprint —
     * the mode is derived from shipment state, never chosen by the caller).
     * A print is a READ operation, so this stays narrower than
     * OrderPolicy::view on purpose: keuangan/korsal/sales can already VIEW
     * this order but must never operate the fulfillment/print flow
     * ("Keuangan fokus financial operation", korsal/sales never get shipment
     * print permission — Blueprint print requirements). A kurir may only
     * print a shipment actually assigned to THEM, not merely one in their
     * agent's branch — WHICH shipment is "theirs" mirrors
     * CourierService::updateShipmentStatus's own ownership check
     * (Shipment.courier_id -> Courier.user_id === $user->id), not just the
     * agent_id match ShipmentPolicy::updateStatus uses (that one is looser
     * because it also covers self-assignment on first pickup).
     */
    public function printReceipt(User $user, Shipment $shipment): bool
    {
        // A cancelled order was never handed to a courier and never will be —
        // printing its resi has no business purpose, for any role including
        // super_admin (Blueprint print requirement: no print for cancelled
        // orders "tanpa business reason").
        if ($shipment->order?->status === 'dibatalkan') {
            return false;
        }

        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($user->isRole('agen', 'admin')) {
            return $shipment->order?->agent_id === $user->agent_id;
        }

        if ($user->isRole('kurir')) {
            return $shipment->courier_id !== null && $shipment->courier?->user_id === $user->id;
        }

        return false;
    }
}
