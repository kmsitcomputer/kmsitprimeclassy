<?php

namespace App\Http\Controllers\Api\V1\Return;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Return\ReviewReturnRequest;
use App\Http\Requests\Return\StoreReturnRequest;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order;
use App\Models\ReturnItem;
use App\Models\ReturnRequest as ReturnRequestModel;
use App\Services\Order\ReturnService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returnService) {}

    /** "Tambahkan authorization agar konsumen hanya dapat mengajukan return untuk order miliknya." */
    public function store(StoreReturnRequest $request, Order $order)
    {
        if ($order->konsumen_id !== $request->user()->id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $return = $this->returnService->requestReturn(
            $order, $request->user(), $request->array('items'), $request->string('reason')->toString(), $request->file('evidence'),
        );

        return $this->created(new ReturnRequestResource($return), __('messages.return.requested'));
    }

    /** Admin dashboard: return list — scoped per branch like order visibility. */
    public function index(Request $request)
    {
        $actor = $request->user();

        $query = ReturnRequestModel::query()
            ->with(['order.konsumen', 'requestedBy', 'reviewedBy', 'items.orderItem'])
            ->whereHas('order', function ($q) use ($actor, $request) {
                if (! $actor->isRole('super_admin')) {
                    $q->where('agent_id', $actor->agent_id);
                } elseif ($request->filled('agent_id')) {
                    $q->where('agent_id', $request->integer('agent_id'));
                }
            })
            ->latest();

        // Optional status filter (comma list), e.g. the dashboard's "Retur Tertunda" = requested,under_review
        // (awaiting Admin review). Read-only narrowing inside the same branch scope above.
        if ($request->filled('status')) {
            $query->whereIn('status', array_filter(explode(',', $request->string('status')->toString())));
        }

        $returns = $query->paginate($request->integer('per_page', 15));

        return $this->ok(ReturnRequestResource::collection($returns)->resolve(), meta: [
            'current_page' => $returns->currentPage(), 'last_page' => $returns->lastPage(), 'total' => $returns->total(),
        ]);
    }

    public function show(Request $request, ReturnRequestModel $return)
    {
        $this->authorizeBranch($request, $return);

        return $this->ok(new ReturnRequestResource($return->load(['order.konsumen', 'requestedBy', 'reviewedBy', 'items.orderItem'])));
    }

    /**
     * Gudang inspection queue — the branch's approved return lines that still need a physical
     * condition inspection (approved + never inspected). Deliberately item-level: a multi-item
     * return stays in the queue until every eligible line has been inspected, and lines that are
     * already inspected, rejected, still pending review, or belong to another branch are never
     * returned. Read-only; all mutations stay on the existing inspect/finalize endpoints.
     */
    public function warehouseQueue(Request $request)
    {
        $actor = $request->user();

        // Defence in depth — the route group is already role:gudang, but the branch scope must
        // never depend on the request or on a global scope alone.
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $inspectable = fn ($query) => $query->where('status', 'approved')->whereNull('inspected_at');

        $returns = ReturnRequestModel::query()
            ->where('status', 'approved')
            ->whereHas('order', fn ($query) => $query->where('agent_id', $actor->agent_id))
            ->whereHas('items', $inspectable)
            ->with([
                'order.konsumen',
                'requestedBy',
                'items' => fn ($query) => $inspectable($query)->with('orderItem'),
            ])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->ok(ReturnRequestResource::collection($returns)->resolve(), meta: [
            'current_page' => $returns->currentPage(), 'last_page' => $returns->lastPage(), 'total' => $returns->total(),
        ]);
    }

    public function review(ReviewReturnRequest $request, ReturnRequestModel $return)
    {
        $this->authorizeBranch($request, $return);

        $return = $this->returnService->review($return, $request->user(), $request->boolean('approved'), $request->input('note'));

        return $this->ok(new ReturnRequestResource($return->load(['order.konsumen', 'requestedBy', 'reviewedBy', 'items.orderItem'])), __('messages.return.reviewed'));
    }

    public function markItemRefunded(Request $request, ReturnItem $item)
    {
        $return = $item->returnRequest;
        $this->authorizeBranch($request, $return);

        $item = $this->returnService->markItemRefunded($item, $request->user());

        return $this->ok($item->fresh(), __('messages.return.refund_marked'));
    }

    public function inspect(Request $request, ReturnItem $item)
    {
        $return = $item->returnRequest;
        $this->authorizeBranch($request, $return);
        $data = $request->validate([
            'received_quantity' => ['required', 'integer', 'min:0'],
            'good_quantity' => ['required', 'integer', 'min:0'],
            'damaged_quantity' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if (! $request->user()->isRole('gudang')) {
            abort(403);
        }

        return $this->ok($this->returnService->inspectReturn($item, $request->user(), $data['received_quantity'], $data['good_quantity'], $data['damaged_quantity'], $data['note'] ?? null));
    }

    public function finalize(Request $request, ReturnItem $item)
    {
        $return = $item->returnRequest;
        $this->authorizeBranch($request, $return);
        if (! $request->user()->isRole('admin')) {
            abort(403);
        }

        return $this->ok($this->returnService->finalizeInspection($item, $request->user()));
    }

    private function authorizeBranch(Request $request, ReturnRequestModel $return): void
    {
        $actor = $request->user();

        if ($actor->isRole('super_admin')) {
            return;
        }

        // Order::withoutGlobalScopes() — BelongsToAgentScope filters by the
        // ACTING user's own agent_id, so a lazy $return->order load would
        // resolve to null for a cross-branch actor instead of letting us
        // compare agent ids and correctly reject with 403.
        $orderAgentId = Order::withoutGlobalScopes()->where('id', $return->order_id)->value('agent_id');

        // value() is a raw query-builder read (no model cast) — normalise both
        // sides so the branch check can't fail on a driver that yields strings.
        if ((int) $orderAgentId !== (int) $actor->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    /**
     * GAP-A (OD-P2-A): set/change the return pickup schedule. Staff authority =
     * the same route group that reviews returns (super_admin/agen/admin);
     * branch scope is enforced by authorizeBranch() exactly like every other
     * mutator here. A NEW schedule may never be in the past (WIB business
     * calendar). Only items still awaiting pickup (return status
     * pending/approved) get the date — already-collected/rejected items are
     * never rewritten, historical data is never touched, and every change is
     * audited.
     */
    public function schedulePickup(Request $request, \App\Models\ReturnRequest $return)
    {
        $this->authorizeBranch($request, $return);

        $data = $request->validate([
            'pickup_scheduled_for' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Jakarta')->toDateString()],
        ]);

        $items = $return->items()->whereIn('status', ['pending', 'approved'])->get();
        if ($items->isEmpty()) {
            throw new ApiException('Tidak ada item retur yang masih menunggu penjemputan.', 422);
        }
        foreach ($items as $item) {
            $item->update(['pickup_scheduled_for' => $data['pickup_scheduled_for']]);
        }
        \App\Services\Logging\ActivityLogger::log($request->user()->id, $return, 'return.pickup_scheduled', null, [
            'return_id' => $return->id, 'scheduled_for' => $data['pickup_scheduled_for'], 'items' => $items->count(),
        ]);

        return $this->ok([
            'return_id' => $return->id,
            'pickup_scheduled_for' => $data['pickup_scheduled_for'],
            'items' => $items->count(),
        ]);
    }

    /**
     * Owner decision (2026-11-08): the ONLY way a return pickup gets a kurir. Office/dispatch actors
     * (super_admin/agen/admin/koordinator-kurir) assign an eligible same-branch courier; the service
     * re-checks branch, return state and courier eligibility under row locks.
     */
    public function assignPickupCourier(Request $request, ReturnItem $item)
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', 'exists:couriers,id'],
            'expected_courier_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = $this->returnService->assignPickupCourier(
            $item, \App\Models\Courier::query()->findOrFail($data['courier_id']), $request->user(),
            isset($data['expected_courier_id']) ? (int) $data['expected_courier_id'] : null,
        );

        return $this->ok(['id' => $item->id, 'courier_id' => $item->courier_id], __('messages.courier.assigned'));
    }
}
