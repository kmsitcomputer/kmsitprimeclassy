<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Role capability (can this role ever see orders?) is intentionally not
     * checked here — every internal role and konsumen can view *some* orders.
     * The real gate is ownership, checked per-record below. The global
     * BelongsToAgentScope on Order already filters non-super_admin queries
     * to the user's own agent branch; this re-checks explicitly per record
     * as a second, independent layer (defense in depth).
     */
    public function view(User $user, Order $order): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($user->isRole('agen', 'admin', 'keuangan')) {
            return $order->agent_id === $user->agent_id;
        }

        if ($user->isRole('korsal')) {
            return $order->korsal_id === $user->id;
        }

        if ($user->isRole('sales')) {
            return $order->sales_id === $user->id;
        }

        if ($user->isRole('konsumen')) {
            return $order->konsumen_id === $user->id;
        }

        // "Kurir dapat melihat order sesuai network agen" — not limited to
        // deliveries already assigned to them (they need to see what's
        // available to pick up too); WHICH fields/actions they get from
        // there (no price/fee/payment) is controlled elsewhere, never here.
        if ($user->isRole('kurir')) {
            return $order->agent_id === $user->agent_id;
        }

        return false;
    }

    /**
     * WHO may ever attempt a cancellation on this record — WHEN it's
     * actually allowed (status + COD/non-COD business rule) is enforced in
     * OrderService::cancel, identically regardless of role.
     * "Consumer dapat membatalkan order sesuai status yang diizinkan.
     * Agen/Korsal/Sales dapat membatalkan sesuai permission... Admin dapat
     * membatalkan semua transaksi sesuai business rule."
     */
    public function cancel(User $user, Order $order): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        if ($user->isRole('agen', 'admin')) {
            return $order->agent_id === $user->agent_id;
        }

        if ($user->isRole('korsal')) {
            return $order->korsal_id === $user->id;
        }

        if ($user->isRole('sales')) {
            return $order->sales_id === $user->id;
        }

        if ($user->isRole('konsumen')) {
            return $order->konsumen_id === $user->id;
        }

        return false;
    }

    /**
     * Konsumen always creates their own order. agen/korsal/sales may place an
     * order on behalf of a konsumen, but only one within their own network
     * (Blueprint: "AGEN/KORSAL/SALES dapat membuat order") — never an
     * arbitrary konsumen_id from another branch.
     */
    public function create(User $user, User $targetKonsumen): bool
    {
        if ($user->id === $targetKonsumen->id) {
            return $user->isRole('konsumen');
        }

        if (! $targetKonsumen->isRole('konsumen')) {
            return false;
        }

        return match (true) {
            $user->isRole('agen') => $targetKonsumen->agent_id === $user->agent_id,
            $user->isRole('korsal') => $targetKonsumen->korsal_id === $user->id,
            $user->isRole('sales') => $targetKonsumen->sales_id === $user->id,
            default => false,
        };
    }

    /**
     * "ADMIN dapat mengelola order sesuai agen" — admin/agen move an order
     * through diproses/dikirim/terkirim in bulk for their own branch;
     * super_admin anywhere. Kurir never reaches this order-wide endpoint —
     * their diproses->dikirim->terkirim actions are scoped to their own
     * Shipment instead (see ShipmentPolicy::updateStatus/CourierService),
     * since a single order can now span several couriers. Sales/korsal/
     * konsumen never do either.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('agen', 'admin') && $order->agent_id === $user->agent_id;
    }

    /** Assigning a courier is an office decision (agen/admin/super_admin) — a courier self-assigns instead, via ShipmentPolicy::updateStatus. */
    public function assignCourier(User $user, Order $order): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('agen', 'admin') && $order->agent_id === $user->agent_id;
    }

    /**
     * Fulfillment quantity/delivery-date changes touch price and refund/
     * additional-payment money — office-only (super_admin/agen/admin).
     * "Kurir tidak boleh... mengubah harga... mengubah payment" — kurir must
     * never reach this, unlike updateStatus which they share.
     */
    public function manageFulfillment(User $user, Order $order): bool
    {
        if ($user->isRole('super_admin')) {
            return true;
        }

        return $user->isRole('agen', 'admin') && $order->agent_id === $user->agent_id;
    }
}
