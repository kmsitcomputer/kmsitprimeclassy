<?php

namespace App\Services\Order;

use App\Models\Courier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * CR-SALES-KURIR-SUB-DELIVERY-001: which Sales-Kurir-Sub may be assigned as the
 * courier of a STANDARD shipment.
 *
 * A Sales-Kurir-Sub is eligible for exactly one order's standard delivery work
 * when ALL of these hold (checked server-side, never from frontend input):
 *
 *   - the candidate is an active `sales-kurir-sub` in the SAME Agent branch as
 *     the order (and the order's konsumen);
 *   - the shipment is `standard` (a `self_sub` shipment keeps its owning
 *     executor and its `courier_id` ban — CHECK + triggers untouched);
 *   - the candidate owns the sale: the order is their own purchase
 *     (`orders.konsumen_id` = them), the order snapshot points at them
 *     (`orders.sales_id` = them), or the konsumen is CURRENTLY in their
 *     referral scope (`users.sales_id` = them, same Agent — mirrors
 *     OrderPolicy::payOnBehalf / the IMP-004 view rule, so an audited
 *     reassignment moves eligibility with the konsumen).
 *
 * Stock and commission are unaffected by the assignment itself: stock is still
 * consumed once on shipment, and the courier fee still credits exactly the
 * recorded executor on delivery (never merged with the sales fee).
 */
class SalesKurirSubEligibilityService
{
    public function isEligibleForOrder(User $candidate, Order $order, ?User $konsumen = null): bool
    {
        if (! $candidate->isRole('sales-kurir-sub')) {
            return false;
        }

        if (($candidate->status ?? null) !== 'active') {
            return false;
        }

        if ($candidate->agent_id === null || (int) $order->agent_id !== (int) $candidate->agent_id) {
            return false;
        }

        $konsumen ??= $order->konsumen;

        // Own purchase: the buyer IS the Sales-Kurir-Sub (self-purchase keeps
        // their account role; sales_id may be empty so the snapshot alone
        // would miss this case).
        if ((int) $order->konsumen_id === (int) $candidate->id) {
            return true;
        }

        // Snapshot ownership: the order was recorded as this seller's sale.
        if ($order->sales_id !== null && (int) $order->sales_id === (int) $candidate->id) {
            return true;
        }

        // Current referral scope: the konsumen is a konsumen TODAY in this
        // seller's downline and branch (audited reassignments move this with
        // the konsumen; knowing an order id grants nothing).
        return $konsumen !== null
            && $konsumen->id !== $candidate->id
            && $konsumen->isRole('konsumen')
            && $konsumen->agent_id !== null
            && (int) $konsumen->agent_id === (int) $candidate->agent_id
            && $konsumen->sales_id !== null
            && (int) $konsumen->sales_id === (int) $candidate->id;
    }

    public function isEligibleForShipment(User $candidate, Shipment $shipment, ?Order $order = null, ?User $konsumen = null): bool
    {
        $order ??= $shipment->order;

        if (! $order || $shipment->delivery_mode !== Shipment::DELIVERY_MODE_STANDARD) {
            return false;
        }

        return $this->isEligibleForOrder($candidate, $order, $konsumen ?? $order->konsumen);
    }

    /**
     * Every courier an office dispatcher may pick for THIS shipment: the
     * branch's active kurir/koordinator-kurir couriers (the FIX-422 scope)
     * plus the eligible Sales-Kurir-Sub executors of this shipment only —
     * never every Sales-Kurir-Sub in the branch.
     *
     * READ-ONLY: this method never creates rows. A legacy Sales-Kurir-Sub
     * without a Courier profile is listed with `courier_id = null` (plus
     * `user_id`); the profile is created on the assignment write path
     * (CourierService::resolveSalesKurirSubExecutor), never on GET.
     *
     * @return Collection<int, array{courier_id: ?int, user_id: ?int, name: string, executor_role: ?string}>
     */
    public function eligibleCouriersForShipment(Shipment $shipment): Collection
    {
        $order = $shipment->order;

        if (! $order || (int) $order->agent_id <= 0) {
            return collect();
        }

        $agentId = (int) $order->agent_id;

        $rows = Courier::query()
            ->with(['user.role'])
            ->where('agent_id', $agentId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereHas('user', fn ($user) => $user
                ->where('agent_id', $agentId)->where('status', 'active')
                ->whereHas('role', fn ($role) => $role->whereIn('slug', ['kurir', 'koordinator-kurir']))))
            ->orderBy('name')
            ->get()
            ->map(fn (Courier $courier) => [
                'courier_id' => $courier->id,
                'user_id' => $courier->user_id,
                'name' => $courier->name,
                'executor_role' => $courier->user?->role?->slug,
            ]);

        if ($shipment->delivery_mode !== Shipment::DELIVERY_MODE_STANDARD) {
            return $rows->sortBy('name')->values();
        }

        $konsumen = $order->konsumen;

        $candidates = User::query()
            ->with(['role'])
            ->where('agent_id', $agentId)
            ->where('status', 'active')
            ->whereHas('role', fn ($role) => $role->whereIn('slug', ['sales-kurir-sub', 'sales-kurir']))
            ->get()
            ->filter(fn (User $user) => $this->isEligibleForOrder($user, $order, $konsumen));

        foreach ($candidates as $candidate) {
            $profile = Courier::query()->where('user_id', $candidate->id)->first();
            if ($profile && (! $profile->is_active || (int) $profile->agent_id !== $agentId)) {
                continue; // exists but unusable — the assignment path refuses it as well.
            }
            $rows->push([
                'courier_id' => $profile?->id,
                'user_id' => $candidate->id,
                'name' => $candidate->name,
                'executor_role' => $candidate->role?->slug,
            ]);
        }

        return $rows->sortBy('name')->values();
    }
}
