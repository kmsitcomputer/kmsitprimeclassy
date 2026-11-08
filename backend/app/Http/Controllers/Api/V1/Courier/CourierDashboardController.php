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
use App\Models\Shipment;
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
     * The delivery queue.
     *
     * Owner decision (2026-11-08): Kurir and Koordinator-Kurir executors are ASSIGNMENT-ONLY — the
     * queue is filtered server-side to standard shipments recorded to the actor's own Courier
     * profile. Unassigned work and other couriers' work never appear (no pre-claim queue).
     * They never see a self_sub shipment.
     *
     * R-03: a Sales-Kurir-Sub is NOT a normal Kurir — they may only ever see orders containing
     * their OWN self_sub shipment on an order THEY placed (creator-only self-pickup:
     * Shipment::scopeSelfPickupOwnedBy). They must never see Agent-sourced unassigned standard
     * shipments or another Sales-Kurir-Sub's self_sub shipment.
     *
     * CR-SALES-KURIR-SUB-DELIVERY-001: plus the standard shipments explicitly assigned to their
     * own Courier profile by an office dispatcher (courier_id = own profile) — unassigned or
     * another courier's standard shipments stay invisible (no self-assignment surface).
     */
    public function orders(Request $request)
    {
        $actor = $request->user();
        $courierId = $actor->courierProfile?->id;
        $isSubActor = $actor->isRole('sales-kurir-sub');
        $isKoordinator = $actor->isRole('koordinator-kurir');
        $status = $request->string('status')->toString();
        $validStatuses = ['diproses', 'dikirim', 'terkirim'];

        $orders = Order::query()
            ->where('agent_id', $actor->agent_id)
            ->whereHas('items', function ($q) use ($courierId, $actor, $isSubActor, $isKoordinator, $status, $validStatuses) {
                // Grouped in its own closure — an ungrouped top-level orWhere()
                // here would escape whereHas's own order_id correlation constraint.
                $q->where(function ($sq) use ($courierId, $actor, $isSubActor, $isKoordinator, $status, $validStatuses) {
                    if ($isSubActor) {
                        // Sales-Kurir-Sub: their own self_sub shipments for every status (their
                        // "Selesai" history is status=terkirim), PLUS standard shipments explicitly
                        // assigned to their own Courier profile. Never an unassigned or another
                        // courier's standard shipment, and never another Sales-Kurir-Sub's shipment.
                        $statuses = in_array($status, $validStatuses, true) ? [$status] : ['diproses', 'dikirim'];
                        $sq->whereIn('status', $statuses)
                            ->where(function ($wq) use ($actor, $courierId) {
                                // Creator-only SKS self-pickup (Owner decision 2026-11-08).
                                $wq->whereHas('shipment', fn ($ssq) => $ssq->selfPickupOwnedBy($actor));
                                if ($courierId) {
                                    $wq->orWhereHas('shipment', function ($ssq) use ($courierId) {
                                        $ssq->where('delivery_mode', Shipment::DELIVERY_MODE_STANDARD)
                                            ->where('courier_id', $courierId);
                                    });
                                }
                            });

                        return;
                    }

                    // Owner decision (2026-11-08): kurir and koordinator-kurir executors are
                    // assignment-only — they see exactly the standard shipments recorded to their
                    // OWN Courier profile (pending/diproses, dikirim and terkirim alike). Unassigned
                    // and other couriers' shipments never appear; no courier profile = empty queue.
                    $statuses = in_array($status, $validStatuses, true) ? [$status] : ['diproses', 'dikirim'];
                    $sq->whereIn('status', $statuses)->whereHas('shipment', fn ($ssq) => $ssq
                        ->where('delivery_mode', Shipment::DELIVERY_MODE_STANDARD)
                        ->where('courier_id', $courierId ?? 0));
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
     * Owner decision (2026-11-08): return pickups are assignment-only. Only return items an
     * authorized office/dispatch actor explicitly assigned to THIS kurir's Courier profile
     * (return_items.courier_id) are listed; unclaimed returns are invisible to every kurir.
     */
    public function returns(Request $request)
    {
        $actor = $request->user();
        $courierId = $actor->courierProfile?->id;

        $returns = ReturnRequest::query()
            ->whereHas('order', fn ($q) => $q->where('agent_id', $actor->agent_id))
            ->whereHas('items', function ($q) use ($courierId) {
                // GAP-A: an item still waiting to be PHYSICALLY collected is kurir work in both
                // review states (pending/approved); orderItem.status 'pengembalian' marks it.
                $q->whereIn('status', ['pending', 'approved'])
                    ->whereHas('orderItem', fn ($oq) => $oq->where('status', 'pengembalian'))
                    ->where('courier_id', $courierId ?? 0);
            })
            ->with(['order', 'items' => fn ($q) => $q->where('courier_id', $courierId ?? 0)
                ->whereIn('status', ['pending', 'approved'])
                ->whereHas('orderItem', fn ($oq) => $oq->where('status', 'pengembalian'))
                ->with('orderItem')])
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

        return $this->ok($item->only(['id', 'courier_id', 'condition_note', 'pickup_scheduled_for']), __('messages.courier.assigned'));
    }

    /** pengembalian -> terkirim (not actually collected) or pengembalian -> kembali (collected) — never touches refund_status/money. */
    public function confirmReturn(Request $request, ReturnItem $item)
    {
        $this->assertSameBranch($request, $item);
        $validated = $request->validate(['received' => ['required', 'boolean']]);

        $item = $this->returnService->courierConfirmReturn($item, $request->user(), $validated['received']);

        return $this->ok($item->only(['id', 'courier_id', 'status', 'condition_note']), __('messages.courier.return_confirmed'));
    }

    private function assertSameBranch(Request $request, ReturnItem $item): void
    {
        $orderAgentId = Order::withoutGlobalScopes()
            ->whereIn('id', function ($q) use ($item) {
                $q->select('order_id')->from('order_items')->where('id', $item->order_item_id);
            })
            ->value('agent_id');

        // value() is a raw query-builder read (no model cast) — normalise both
        // sides so the branch check can't fail on a driver that yields strings.
        if ((int) $orderAgentId !== (int) $request->user()->agent_id) {
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

    /** GAP-A (OD-P2-A): this kurir's scheduled return pickups for TODAY (WIB) — the shared ReturnService derivation, so dashboard and KPI always agree. */
    public function pickupToday(Request $request)
    {
        $date = now('Asia/Jakarta')->toDateString();

        return $this->ok([
            'date' => $date,
            'count' => $this->returnService->scheduledPickupsToday($request->user()),
        ]);
    }
}
