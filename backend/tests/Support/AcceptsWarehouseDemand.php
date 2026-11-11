<?php

namespace Tests\Support;

use App\Models\StockRequest;
use App\Models\StockRequestItem;

/**
 * AUTHORIZATION SHORTCUT — NOT INVENTORY EVIDENCE. (F-test-quality)
 *
 * Puts an order's demand in the "fully accepted" state so dispatch/courier/payment tests can reach their own
 * subject. It records a clearly-labelled fixture movement for any Shipping stock it adds, but it does NOT
 * consume reservation or take Transit stock, so a test using it must never assert stock conservation.
 * Conservation is asserted only by tests that run the REAL flow (Gudang proposal → Koordinator receipt):
 * CoordinatorReceiptTest, DeliveryBatchRefusalTest, PartialCancellationTest, StockDiscrepancyTest.
 *
 * REM-003 fixture helper — courier assignment now requires the Koordinator's accepted receipt
 * (StockRequestItem.fulfilled_qty). Tests that are about dispatch/courier behaviour (not about the
 * receipt itself) use this to put the order's Agent demand in the "fully accepted" state without
 * re-running the whole Gudang → Koordinator flow. Receipt behaviour is covered by
 * CoordinatorReceiptTest / CourierAssignmentReceiptGateTest.
 */
trait AcceptsWarehouseDemand
{
    /** Accept every open Stock Request (fixture shortcut for dispatch-focused tests). */
    protected function acceptAllWarehouseDemand(): void
    {
        foreach (StockRequest::withoutGlobalScopes()->pluck('order_id') as $orderId) {
            $this->acceptWarehouseDemand((int) $orderId);
        }
    }

    protected function acceptWarehouseDemand(int $orderId, ?int $quantity = null, bool $withShippingStock = false): void
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $orderId)->first();
        if (! $request) {
            return;
        }
        foreach (StockRequestItem::query()->where('stock_request_id', $request->id)->get() as $item) {
            $qty = $quantity ?? (int) $item->requested_qty;
            // Keep the physical side honest: accepted units sit in the Shipping bucket (a later reversal needs them).
            $delta = $qty - (int) $item->fulfilled_qty;
            if ($delta > 0 && $withShippingStock) {
                $bucket = \App\Models\WarehouseStock::withoutGlobalScopes()->firstOrCreate(
                    ['agent_id' => $request->agent_id, 'stock_type' => 'shipping', 'product_id' => $item->product_variation_id ? null : $item->product_id, 'product_variation_id' => $item->product_variation_id, 'sub_location_id' => null],
                    ['quantity' => 0]
                );
                $bucket->increment('quantity', $delta);
                \App\Models\StockMovement::create([
                    'agent_id' => $request->agent_id, 'product_id' => $item->product_variation_id ? null : $item->product_id, 'product_variation_id' => $item->product_variation_id,
                    'type' => 'adjustment', 'stock_type' => 'shipping', 'quantity' => $delta,
                    'reference_type' => StockRequest::class, 'reference_id' => $request->id, 'note' => 'TEST_FIXTURE_SHORTCUT accepted demand',
                ]);
            }
            $item->update(['fulfilled_qty' => $qty, 'remaining_qty' => max(0, (int) $item->requested_qty - $qty)]);
        }
        $request->update(['status' => StockRequestItem::query()->where('stock_request_id', $request->id)->sum('remaining_qty') > 0 ? 'partial' : 'fulfilled']);
    }

    /**
     * Fixture shortcut for the (now Koordinator-only, receipt-gated) assignment: accepts the order's
     * demand, then assigns through the real service as a Koordinator Kurir of the shipment's branch.
     */
    protected function assignAsKoordinator(\App\Models\Shipment $shipment, \App\Models\Courier $courier): \App\Models\Shipment
    {
        $order = \App\Models\Order::withoutGlobalScopes()->findOrFail($shipment->order_id);
        $this->acceptWarehouseDemand($order->id);
        $koordinator = \App\Models\User::withoutGlobalScopes()
            ->where('agent_id', $order->agent_id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'koordinator-kurir'))->first()
            ?? \App\Models\User::factory()->koordinatorKurir()->create(['agent_id' => $order->agent_id]);

        return app(\App\Services\Order\CourierService::class)->assignCourier($shipment, $courier, $koordinator);
    }

    /** actingAs() the branch's Koordinator Kurir (created if absent) after accepting all open demand. */
    protected function actingAsDispatcher(\App\Models\User $branchUser): static
    {
        $this->acceptAllWarehouseDemand();
        $agentId = $branchUser->agent_id ?? $branchUser->id;
        $koordinator = \App\Models\User::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->whereHas('role', fn ($q) => $q->where('slug', 'koordinator-kurir'))->first()
            ?? \App\Models\User::factory()->koordinatorKurir()->create(['agent_id' => $agentId]);

        return $this->actingAs($koordinator);
    }

    /**
     * F02 fixture shortcut: the office bulk `dikirim` now requires every Kurir Toko shipment to be assigned and
     * accepted. Fixtures that only need "the order has shipped" call this first (idempotent; SKS self_sub,
     * pickup and expedition shipments are left alone). NOT used by the tests that assert the guard itself.
     */
    protected function makeDispatchable(int $orderId): void
    {
        $order = \App\Models\Order::withoutGlobalScopes()->find($orderId);
        if (! $order) {
            return;
        }
        $this->acceptWarehouseDemand($orderId, null, false); // authorization only — no physical stock invented
        foreach (\App\Models\Shipment::query()->where('order_id', $orderId)->whereNull('courier_id')->get() as $shipment) {
            if (! \App\Services\Order\CourierService::isKurirTokoShipment($shipment) || $shipment->isSelfDelivery()) {
                continue;
            }
            $courier = \App\Models\Courier::query()->where('agent_id', $order->agent_id)->where('is_active', true)->first()
                ?? \App\Models\Courier::create(['type' => 'internal', 'user_id' => null, 'agent_id' => $order->agent_id, 'name' => 'Fixture Kurir', 'is_active' => true]);
            \Illuminate\Support\Facades\DB::table('shipments')->where('id', $shipment->id)->update(['courier_id' => $courier->id]);
        }
    }
}
