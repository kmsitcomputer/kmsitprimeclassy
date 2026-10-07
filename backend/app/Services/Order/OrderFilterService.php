<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Single interpretation of the order filters shared by Koordinator Kurir → Dispatch and the
 * role Order lists, so the same filter can never mean two different things.
 *
 * Every method only NARROWS an already role-scoped query; none of them ever widens access.
 */
class OrderFilterService
{
    /** Canonical settlement states stored in orders.payment_status (rejected proof ⇒ 'failed'). */
    public const SETTLEMENT_STATUSES = [
        'unpaid', 'pending_verification', 'partially_paid', 'paid', 'failed', 'partially_refunded', 'refunded',
    ];

    /**
     * Dispatch-equivalent filters: delivery_date (any item), canonical region ids, paid=paid|unpaid.
     * Used verbatim by DispatchController.
     */
    public function applyDispatchFilters(Builder $query, Request $request): Builder
    {
        foreach (['province_id', 'regency_id', 'district_id', 'village_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->string($column)->toString());
            }
        }

        if ($request->filled('delivery_date')) {
            $date = $request->string('delivery_date')->toString();
            $query->whereHas('items', fn ($item) => $item->whereDate('requested_delivery_date', $date));
        }

        if ($request->filled('paid')) {
            $paid = $request->string('paid')->toString() === 'paid';
            $query->where(
                $paid
                    ? fn ($q) => $q->where('payment_status', 'paid')
                    : fn ($q) => $q->whereIn('payment_status', ['unpaid', 'pending_verification', 'partially_paid'])
            );
        }

        return $query;
    }

    /** Status Order (canonical Order::status) and Status Pelunasan (canonical payment_status). */
    public function applyStatusFilters(Builder $query, Request $request, bool $financial): Builder
    {
        if ($request->filled('status') && in_array($request->string('status')->toString(), array_keys(Order::TRANSITIONS), true)) {
            $query->where('status', $request->string('status')->toString());
        }

        // Operational roles (gudang, koordinator, kurir) never see money state: do not let them probe it.
        if ($financial && $request->filled('payment_status')
            && in_array($request->string('payment_status')->toString(), self::SETTLEMENT_STATUSES, true)) {
            $query->where('payment_status', $request->string('payment_status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->toString()).'%';
            $query->where(fn ($q) => $q->where('order_no', 'like', $term)
                ->orWhere('recipient_name_snapshot', 'like', $term));
        }

        return $query;
    }
}
