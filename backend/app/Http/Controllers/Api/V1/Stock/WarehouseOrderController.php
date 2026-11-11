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
            ->where(function ($scope) {
                // Legacy rule (unchanged): diproses and no courier / self-delivery yet.
                $scope->where(fn ($legacy) => $legacy->where('status', 'diproses')
                    ->whereDoesntHave('shipments', fn ($shipment) => $shipment
                        ->whereNotNull('courier_id')
                        ->orWhereNotNull('self_delivered_by_user_id')))
                    // REM-001: outstanding Agent demand keeps the order visible for later batches.
                    ->orWhere(fn ($batch) => $batch->whereIn('status', ['diproses', 'dikirim'])
                        ->whereDoesntHave('shipments', fn ($shipment) => $shipment->whereNotNull('self_delivered_by_user_id'))
                        ->whereIn('id', StockRequest::withoutGlobalScopes()->whereIn('status', ['pending', 'partial'])->select('order_id')));
            })
            // `requested=1`: only orders the Koordinator Kurir has asked Gudang to fulfil and that still have demand open.
            ->when($request->boolean('requested'), fn ($q) => $q->whereIn('id', StockRequest::withoutGlobalScopes()
                ->whereNotNull('coordinator_requested_at')->whereIn('status', ['pending', 'partial'])->select('order_id')))
            // The Koordinator's requests first (newest request first), then the rest by recency.
            ->orderByRaw('(select max(sr.coordinator_requested_at) from stock_requests sr where sr.order_id = orders.id and sr.status in (\'pending\', \'partial\')) is null')
            ->orderByRaw('(select max(sr.coordinator_requested_at) from stock_requests sr where sr.order_id = orders.id and sr.status in (\'pending\', \'partial\')) desc')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        $data = OrderResource::collection($orders)->resolve();
        $requests = StockRequest::withoutGlobalScopes()->whereIn('order_id', $orders->pluck('id'))->with('items')->get()->keyBy('order_id');
        $coordinators = \App\Models\User::query()->whereIn('id', $requests->pluck('coordinator_requested_by')->filter())->pluck('name', 'id');
        $awaiting = \App\Models\StockRequestProposalItem::query()
            ->whereHas('proposal', fn ($q) => $q->withoutGlobalScopes()->where('status', 'pending')->whereIn('stock_request_id', $requests->pluck('id')))
            ->join('stock_request_proposals as p', 'p.id', '=', 'stock_request_proposal_items.stock_request_proposal_id')
            ->selectRaw('p.stock_request_id, SUM(stock_request_proposal_items.quantity) AS qty')->groupBy('p.stock_request_id')->pluck('qty', 'stock_request_id');

        // Real data only: identity and time come from the request row; nothing is inferred when the Koordinator never asked.
        $data = array_map(function (array $row) use ($requests, $coordinators, $awaiting) {
            $sr = $requests->get($row['id']);
            $row['fulfillment_request'] = $sr ? [
                'stock_request_id' => $sr->id,
                'request_number' => $sr->request_number,
                'status' => $sr->status,
                'requested_by_coordinator' => $sr->coordinator_requested_at !== null,
                'coordinator' => $sr->coordinator_requested_by ? ['id' => $sr->coordinator_requested_by, 'name' => $coordinators[$sr->coordinator_requested_by] ?? null] : null,
                'requested_at' => $sr->coordinator_requested_at?->toIso8601String(),
                'note' => $sr->coordinator_request_note,
                'requested_qty' => (int) $sr->items->sum('requested_qty'),
                'handed_over_qty' => (int) $sr->items->sum('fulfilled_qty'),
                'awaiting_receipt_qty' => (int) ($awaiting[$sr->id] ?? 0),
                'outstanding_qty' => (int) $sr->items->sum('remaining_qty'),
            ] : null;

            return $row;
        }, $data);

        return $this->ok($data, meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
            'requested_total' => StockRequest::query()->whereNotNull('coordinator_requested_at')->whereIn('status', ['pending', 'partial'])->count(),
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
        if ($order->agent_id !== $user->agent_id || ! $order->isInWarehouseScope()) {
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