<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\StockRequest;
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
}
