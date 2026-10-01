<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockRequestService
{
    public function createForOrderWhenProcessing(Order $order): ?StockRequest
    {
        return DB::transaction(function () use ($order) {
            $order = Order::withoutGlobalScopes()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->first();
            if ($existing) {
                return $existing->load('items');
            }
            if ($order->status !== 'diproses') {
                throw new ApiException('Stock Request hanya dibuat saat order diproses.', 422);
            }
            // Sub-sourced items are fulfilled from the Sales-Kurir-Sub's own Sub Location, not the Agent's
            // Transit -> Shipping flow. An order made only of Sub items has no warehouse demand at all.
            $agentItems = $order->items->reject(fn ($item) => $item->isSubSourced());
            if ($agentItems->isEmpty()) {
                return null;
            }
            $request = StockRequest::create(['agent_id' => $order->agent_id, 'order_id' => $order->id, 'request_number' => $this->uniqueNumber(), 'status' => 'pending']);
            foreach ($agentItems as $item) {
                $request->items()->create(['order_item_id' => $item->id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'sku_snapshot' => $item->sku_snapshot, 'requested_qty' => $item->original_quantity, 'fulfilled_qty' => 0, 'remaining_qty' => $item->original_quantity]);
            }

            return $request->load('items');
        });
    }

    private function uniqueNumber(): string
    {
        do {
            $number = 'SR-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));
        } while (DB::table('stock_requests')->where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Package C / SC-03: locks the order's one-per-order Stock Request and returns it. This is the
     * FIRST lock the addition path takes after the Order row — the warehouse proposal/approval path
     * also holds the Stock Request before it touches any inventory row, so both paths now share one
     * order (Order -> Stock Request -> inventory) and cannot deadlock against each other.
     *
     * A missing or cancelled request is a 422: the addition never silently leaves demand untracked.
     */
    public function lockActiveRequestForOrder(Order $order): StockRequest
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();

        if (! $request || $request->status === 'cancelled') {
            throw new ApiException(__('messages.order.line_addition_stock_request_unavailable'), 422);
        }

        return $request;
    }

    /**
     * Package C / SC-03: reconcile the order's existing one-per-order Stock Request with a line that
     * was added to an already-`diproses` order. Reuses the canonical request/items mechanism — never
     * creates a second Stock Request — and is idempotent per OrderItem (the unique `order_item_id`
     * invariant is preserved: an existing line for this item is returned, not duplicated).
     *
     * New outstanding demand re-opens a `fulfilled` request (same status formula as the warehouse
     * approval path: pending / partial / fulfilled from the item sums) so Gudang can propose it.
     * Historical fulfilled_qty values are never touched.
     *
     * Sub-sourced items carry no Agent warehouse demand, so they are rejected here (SC-03 is
     * Agent-only anyway). A missing or already-cancelled request is reported as a 422.
     */
    public function appendItemForOrderItem(Order $order, OrderItem $item): ?StockRequestItem
    {
        return DB::transaction(function () use ($order, $item) {
            if ($item->isSubSourced()) {
                throw new ApiException(__('messages.order.line_addition_sub_not_supported'), 422);
            }

            $request = $this->lockActiveRequestForOrder($order);

            $existing = StockRequestItem::query()
                ->where('stock_request_id', $request->id)
                ->where('order_item_id', $item->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = $request->items()->create([
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variation_id' => $item->product_variation_id,
                'sku_snapshot' => $item->sku_snapshot,
                'requested_qty' => $item->original_quantity,
                'fulfilled_qty' => 0,
                'remaining_qty' => $item->original_quantity,
            ]);

            $this->reconcileStatus($request);

            return $created;
        });
    }

    /**
     * Production UAT (root cause C): a quantity adjustment on an order line must be mirrored on the order's
     * Stock Request item, which is the canonical inventory REQUIREMENT caused by the order. Called FIRST
     * (right after the Order lock) so the lock order stays Order -> Stock Request -> inventory, matching
     * SC-03 and warehouse approval.
     *
     * A reduction may only consume the still-UNFULFILLED remainder: units Gudang already moved to Shipping are
     * warehouse history and are never rewritten (422). Returns the locked item, or null when the order has no
     * active order-generated request / line (Sub-sourced lines, manual flows).
     */
    public function lockItemForQuantityChange(Order $order, OrderItem $item, int $delta): ?StockRequestItem
    {
        if ($item->isSubSourced()) {
            return null;
        }

        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
        if (! $request || $request->status === 'cancelled') {
            return null;
        }

        $requestItem = StockRequestItem::query()
            ->where('stock_request_id', $request->id)
            ->where('order_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if ($requestItem && $delta < 0 && $requestItem->remaining_qty < -$delta) {
            throw new ApiException(__('messages.fulfillment.stock_request_fulfilled_blocks_reduction'), 422);
        }

        return $requestItem;
    }

    /** Applies the order quantity delta to the locked request item and re-derives the request status. */
    public function applyQuantityChange(StockRequestItem $requestItem, int $delta): void
    {
        $requestItem->update([
            'requested_qty' => max(0, $requestItem->requested_qty + $delta),
            'remaining_qty' => max(0, $requestItem->remaining_qty + $delta),
        ]);

        $request = StockRequest::withoutGlobalScopes()->whereKey($requestItem->stock_request_id)->lockForUpdate()->first();
        if ($request) {
            $this->reconcileStatus($request);
        }
    }

    /**
     * Single status formula for an order-generated request (pending / partial / fulfilled). Uses locking
     * reads: under REPEATABLE READ a plain sum() would use the snapshot taken before this transaction
     * waited on a lock and could miss a concurrently committed warehouse approval. A request with no
     * actionable demand and no fulfilment (every line reduced to zero) keeps its status.
     */
    public function reconcileStatus(StockRequest $request): void
    {
        $items = $request->items()->lockForUpdate()->get();
        $remaining = (int) $items->sum('remaining_qty');
        $fulfilled = (int) $items->sum('fulfilled_qty');

        if ($remaining === 0) {
            if ($fulfilled > 0) {
                $request->update(['status' => 'fulfilled', 'fulfilled_at' => $request->fulfilled_at ?? now()]);
            }

            return;
        }

        $request->update(['status' => $fulfilled > 0 ? 'partial' : 'pending', 'fulfilled_at' => null]);
    }
}
