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

        // Self-purchase: the buyer can always see their own order, regardless
        // of role — an agen/korsal/sales buying for themselves has no
        // korsal_id/sales_id snapshot on the order (those columns record who
        // REFERRED the buyer, and a self-purchaser has no separate referrer),
        // so the role-specific branches below would otherwise reject them.
        if ($order->konsumen_id === $user->id) {
            return true;
        }

        if ($user->isRole('agen', 'admin', 'keuangan')) {
            return $order->agent_id === $user->agent_id;
        }

        if ($user->isRole('korsal')) {
            return $order->korsal_id === $user->id;
        }

        if ($user->isRole('sales', 'sales-kurir-sub')) {
            return $order->sales_id === $user->id;
        }

        if ($user->isRole('konsumen')) {
            return $order->konsumen_id === $user->id;
        }

        // R-04 / §C: a normal Kurir must NOT use the generic order list/detail — the generic
        // OrderResource exposes recipient/contact/address and sibling items, bypassing the
        // pre-claim minimization in the Courier dashboard. Kurir order discovery + assigned
        // delivery work go exclusively through /kurir/orders (CourierOrderResource).
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

        if ($user->isRole('sales', 'sales-kurir-sub')) {
            return $order->sales_id === $user->id;
        }

        if ($user->isRole('konsumen')) {
            return $order->konsumen_id === $user->id;
        }

        return false;
    }

    /**
     * Konsumen always creates their own order. agen/korsal/sales/sales-kurir-sub may place an
     * order on behalf of a konsumen, but only one within their own network
     * (Blueprint: "AGEN/KORSAL/SALES dapat membuat order") — never an
     * arbitrary konsumen_id from another branch. agen/korsal/sales may also
     * self-purchase (buy for themselves, checking out as the buyer) — their
     * account role never changes; "transaction actor" is a separate concept
     * from "account role" (Blueprint §Fee: role tetap, buyer != role change).
     */
    public function create(User $user, User $targetKonsumen): bool
    {
        if ($user->id === $targetKonsumen->id) {
            return $user->isRole('konsumen', 'agen', 'korsal', 'sales', 'sales-kurir-sub');
        }

        if (! $targetKonsumen->isRole('konsumen')) {
            return false;
        }

        return match (true) {
            $user->isRole('agen') => $targetKonsumen->agent_id === $user->agent_id,
            $user->isRole('korsal') => $targetKonsumen->korsal_id === $user->id,
            $user->isRole('sales', 'sales-kurir-sub') => $targetKonsumen->sales_id === $user->id,
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

    /**
     * Package C / SC-03: adding a NEW product/variation line to an existing order is ADMIN ONLY
     * (Human decision, 2026-10-01) — deliberately narrower than manageFulfillment, which stays
     * unchanged for the existing Package A/B fulfillment features (super_admin/agen/admin).
     *
     * Exactly role `admin`, restricted to the order's own branch. Every other role — including
     * super_admin and agen — is denied, and a cross-Agent admin is denied. Defence in depth: this
     * policy runs alongside the dedicated `role:admin` route group and the global
     * BelongsToAgentScope on Order.
     */
    public function addLine(User $user, Order $order): bool
    {
        return $user->isRole('admin') && $order->agent_id === $user->agent_id;
    }
}
