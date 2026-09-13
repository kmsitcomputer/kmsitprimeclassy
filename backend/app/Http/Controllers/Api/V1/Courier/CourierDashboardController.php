<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourierOrderResource;
use App\Http\Resources\CourierReturnResource;
use App\Models\Commission;
use App\Models\Order;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Services\Order\ReturnService;
use Illuminate\Http\Request;

/**
 * Kurir's own dashboard — everything here is either read-only or a pure
 * logistics status flip, scoped to the kurir's own agent branch
 * (Blueprint: "Kurir tidak boleh... melihat data agen lain").
 */
class CourierDashboardController extends Controller
{
    public function __construct(private readonly ReturnService $returnService) {}

    /**
     * The delivery queue: every 'diproses' item in this kurir's branch is
     * visible to EVERY kurir regardless of any pre-assignment — "semua order
     * diproses bisa dilihat semua kurir." Only once an item is actually
     * picked up (self-assigned the moment it's marked 'dikirim' — see
     * CourierService::selfAssignIfUnassigned) does it become exclusive:
     * 'dikirim' items only ever show to the kurir holding that shipment,
     * never a sibling kurir in the same branch (Blueprint: "status dikirim
     * ... tidak bisa dilihat kurir lain").
     */
    public function orders(Request $request)
    {
        $actor = $request->user();
        $courierId = $actor->courierProfile?->id;

        $orders = Order::query()
            ->where('agent_id', $actor->agent_id)
            ->whereHas('items', function ($q) use ($courierId) {
                // Grouped in its own closure — an ungrouped top-level orWhere()
                // here would escape whereHas's own order_id correlation constraint.
                $q->where(function ($sq) use ($courierId) {
                    $sq->where('status', 'diproses')
                        ->orWhere(function ($dq) use ($courierId) {
                            $dq->where('status', 'dikirim')
                                ->whereHas('shipment', fn ($ssq) => $ssq->where('courier_id', $courierId));
                        });
                });
            })
            ->with('items.shipment.courier')
            ->orderBy('delivery_date_estimate')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->ok(CourierOrderResource::collection($orders)->resolve(), meta: [
            'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total(),
        ]);
    }

    /**
     * Return requests in this kurir's branch — unclaimed ones ("pengembalian"
     * with no courier_id yet) are visible to every kurir; once one of them
     * picks it up (pickupReturn(), or courierConfirmReturn() claiming it as a
     * fallback), it disappears from every other kurir's queue.
     */
    public function returns(Request $request)
    {
        $actor = $request->user();
        $courierId = $actor->courierProfile?->id;

        $returns = ReturnRequest::query()
            ->whereHas('order', fn ($q) => $q->where('agent_id', $actor->agent_id))
            ->whereHas('items', function ($q) use ($courierId) {
                $q->where('status', 'pending')
                    ->whereHas('orderItem', fn ($oq) => $oq->where('status', 'pengembalian'))
                    ->where(function ($cq) use ($courierId) {
                        $cq->whereNull('courier_id')->orWhere('courier_id', $courierId);
                    });
            })
            ->with(['order', 'items.orderItem'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->ok(CourierReturnResource::collection($returns)->resolve(), meta: [
            'current_page' => $returns->currentPage(), 'last_page' => $returns->lastPage(), 'total' => $returns->total(),
        ]);
    }

    /** The physical claim on a return's pickup — see ReturnService::pickupReturn's docblock. */
    public function pickupReturn(Request $request, ReturnItem $item)
    {
        $this->assertSameBranch($request, $item);
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $item = $this->returnService->pickupReturn($item, $request->user(), $validated['note'] ?? null);

        return $this->ok($item, __('messages.courier.assigned'));
    }

    /** pengembalian -> terkirim (not actually collected) or pengembalian -> kembali (collected) — never touches refund_status/money. */
    public function confirmReturn(Request $request, ReturnItem $item)
    {
        $this->assertSameBranch($request, $item);
        $validated = $request->validate(['received' => ['required', 'boolean']]);

        $item = $this->returnService->courierConfirmReturn($item, $request->user(), $validated['received']);

        return $this->ok($item, __('messages.courier.return_confirmed'));
    }

    private function assertSameBranch(Request $request, ReturnItem $item): void
    {
        $orderAgentId = Order::withoutGlobalScopes()
            ->whereIn('id', function ($q) use ($item) {
                $q->select('order_id')->from('order_items')->where('id', $item->order_item_id);
            })
            ->value('agent_id');

        if ($orderAgentId !== $request->user()->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    /** "Laporan Rekapan order yang sudah dia kirim" — no monetary figures, just what/when they delivered. */
    public function deliveredReport(Request $request)
    {
        $actor = $request->user();
        $courier = $actor->courierProfile;

        if (! $courier) {
            return $this->ok(['count' => 0, 'orders' => []]);
        }

        $query = Order::query()
            ->where('agent_id', $actor->agent_id)
            ->whereHas('items', fn ($q) => $q->where('status', 'terkirim')
                ->whereHas('shipment', fn ($sq) => $sq->where('courier_id', $courier->id)))
            ->with('items.shipment.courier');

        if ($request->filled('from')) {
            $query->whereDate('updated_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('updated_at', '<=', $request->date('to'));
        }

        $orders = $query->latest('updated_at')->paginate($request->integer('per_page', 20));

        // "Daftar listing order selesai bersama jumlah fee kurir nya" — join
        // this kurir's OWN earned commission per order onto the money-free
        // CourierOrderResource shape; never another kurir's fee, never
        // agent/sales fee amounts (Commission is filtered to this actor only).
        $feesByOrder = Commission::query()
            ->where('beneficiary_user_id', $actor->id)->where('beneficiary_role', 'courier')
            ->whereIn('order_id', collect($orders->items())->pluck('id'))
            ->selectRaw('order_id, SUM(amount) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $items = collect($orders->items())->map(function ($order) use ($feesByOrder) {
            $row = (new CourierOrderResource($order))->resolve();
            $row['fee_amount'] = (float) ($feesByOrder[$order->id] ?? 0);

            return $row;
        });

        return $this->ok($items->values(), meta: [
            'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total(),
        ]);
    }
}
