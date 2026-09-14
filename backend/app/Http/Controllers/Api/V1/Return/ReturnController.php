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
}
