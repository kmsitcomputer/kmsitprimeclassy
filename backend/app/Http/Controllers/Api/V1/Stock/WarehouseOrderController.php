<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\StockRequestResource;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockRequest;
use App\Models\WarehouseStock;
use Illuminate\Http\Request;

/**
 * IMP-001 UAT remediation (Gap 2) — Gudang's "Order Diproses" work queue.
 *
 * The user-facing "Stock Request" feature is gone; Gudang now works the
 * warehouse queue through orders:
 *
 *   Order status = 'diproses' AND no courier assigned  →  visible in Gudang's
 *   "Order Diproses" queue → Gudang opens the order → submits a fulfillment
 *   proposal (canonical StockRequestProposalController) → Admin reviews →
 *   existing canonical fulfillment/inventory process continues.
 *
 * CRITICAL VISIBILITY INVARIANT (server-side, never client-filtered):
 * Gudang may see an order ONLY when BOTH hold:
 *   1. order.status is exactly 'diproses', AND
 *   2. the order has no assigned courier — no Shipment carries
 *      `courier_id`, and no self_sub Shipment has a self-delivering
 *      Sales-Kurir-Sub (`self_delivered_by_user_id`).
 *
 * If either becomes false the order MUST disappear from this queue. This is
 * enforced here in the query AND again in OrderPolicy::view for the detail
 * endpoint — direct URL/API access cannot bypass the scope rule.
 *
 * Gudang gets the operational OrderResource projection only (no financial
 * figures — see OrderResource::toArray's $seesFinancials), exactly like the
 * generic /orders list.
 */
class WarehouseOrderController extends Controller
{
    /**
     * Gudang's work queue — diproses and not yet handed to any courier.
     * BelongsToAgentScope confines the query to the caller's own branch; the
     * status/no-courier filter is applied server-side regardless of input.
     */
    public function diproses(Request $request)
    {
        $user = $request->user();

        if (! $user->isRole('gudang')) {
            abort(403);
        }

        $orders = Order::query()
            ->with(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'shipments.courier.user', 'shipments.proof'])
            ->where('status', 'diproses')
            ->whereDoesntHave('shipments', fn ($shipment) => $shipment
                ->whereNotNull('courier_id')
                ->orWhereNotNull('self_delivered_by_user_id'))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->ok(OrderResource::collection($orders)->resolve(), meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    /**
     * The internal one-per-order stock request behind an eligible order —
     * what Gudang proposes against. This is the canonical persistence path
     * (StockRequest + items), NOT a user-facing "Stock Request" feature:
     * the endpoint is only reachable by a Gudang of the same branch AND the
     * order must still be in the warehouse queue (status 'diproses' and no
     * courier assigned — the exact same scope rule as the queue/detail).
     */
    public function stockRequest(Request $request, Order $order)
    {
        $user = $request->user();

        if (! $user->isRole('gudang')) {
            abort(403);
        }

        // Same-Agent + the visibility invariant — a direct call cannot bypass it.
        if ($order->status !== 'diproses' || $order->agent_id !== $user->agent_id) {
            abort(404);
        }
        $hasCourier = $order->shipments()
            ->where(fn ($q) => $q->whereNotNull('courier_id')->orWhereNotNull('self_delivered_by_user_id'))
            ->exists();
        if ($hasCourier) {
            abort(404);
        }

        $stockRequest = StockRequest::query()
            ->with(['items.product.images', 'items.variation.compositions.option', 'order'])
            ->where('order_id', $order->id)
            ->first();

        if (! $stockRequest) {
            abort(404);
        }

        $stockRequest->items->each(function ($item) use ($user) {
            $item->setAttribute('current_stock', $this->currentBuckets($user->agent_id, $item));
        });

        return $this->ok(new StockRequestResource($stockRequest));
    }

    private function currentBuckets(int $agentId, $item): array
    {
        $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))
            ->value('quantity');
        $shipping = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'shipping')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))
            ->value('quantity');
        $reserved = (int) ($item->product_variation_id
            ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $item->product_variation_id)->value('quantity_reserved')
            : ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $item->product_id)->value('quantity_reserved'));

        return ['transit' => $transit, 'shipping' => $shipping, 'reserved' => $reserved];
    }
}