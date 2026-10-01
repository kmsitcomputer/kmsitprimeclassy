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

            // Locking read (not a plain sum): under REPEATABLE READ a consistent read would use the
            // snapshot taken BEFORE this transaction waited on the Stock Request lock and could miss a
            // concurrently committed warehouse approval (stale fulfilled_qty -> wrong status).
            $lockedItems = $request->items()->lockForUpdate()->get();
            $remaining = (int) $lockedItems->sum('remaining_qty');
            $fulfilled = (int) $lockedItems->sum('fulfilled_qty');
            $request->update([
                'status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'),
                'fulfilled_at' => $remaining === 0 ? ($request->fulfilled_at ?? now()) : null,
            ]);

            return $created;
        });
    }
}
