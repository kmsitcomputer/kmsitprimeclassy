<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockRequestProposalResource;
use App\Models\StockRequest;
use App\Models\StockRequestProposal;
use App\Models\StockRequestProposalItem;
use App\Services\Stock\StockRequestProposalService;
use Illuminate\Http\Request;

class StockRequestProposalController extends Controller
{
    public function __construct(private readonly StockRequestProposalService $proposals) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('admin', 'gudang')) {
            abort(403);
        }

        $proposals = StockRequestProposal::query()
            ->where('agent_id', $actor->agent_id)
            ->with(['items.requestItem.product.images', 'items.requestItem.variation.compositions.option', 'items.requestItem.orderItem', 'requester', 'request.order'])
            ->when($request->string('scope')->toString() === 'mine', fn ($q) => $q->where('requested_by', $actor->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('stock_request_id'), fn ($q) => $q->where('stock_request_id', $request->integer('stock_request_id')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->ok(StockRequestProposalResource::collection($proposals)->resolve(), meta: [
            'current_page' => $proposals->currentPage(), 'last_page' => $proposals->lastPage(), 'total' => $proposals->total(),
        ]);
    }

    public function show(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('view', $proposal);

        return $this->ok(new StockRequestProposalResource($proposal->load(['items.requestItem.product.images', 'items.requestItem.variation.compositions.option', 'items.requestItem.orderItem', 'requester', 'request.order'])));
    }

    public function store(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('propose', StockRequestProposal::class);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->created(new StockRequestProposalResource($this->proposals->propose($request->user(), $stockRequest, $data['items'])));
    }

    public function approve(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('approve', $proposal);

        return $this->ok(new StockRequestProposalResource($this->proposals->approve($request->user(), $proposal)));
    }

    public function reject(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('reject', $proposal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(new StockRequestProposalResource($this->proposals->reject($request->user(), $proposal, $data['reason'])));
    }

    /** Per-product decision: approve ONE proposal line; the other lines stay pending/untouched. */
    public function approveItem(Request $request, StockRequestProposal $proposal, StockRequestProposalItem $item)
    {
        $this->authorize('approve', $proposal);

        return $this->ok(new StockRequestProposalResource($this->proposals->approveItem($request->user(), $proposal, $item)));
    }

    /** Per-product decision: reject ONE proposed fulfilment line (order demand is unchanged). */
    public function rejectItem(Request $request, StockRequestProposal $proposal, StockRequestProposalItem $item)
    {
        $this->authorize('reject', $proposal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(new StockRequestProposalResource($this->proposals->rejectItem($request->user(), $proposal, $item, $data['reason'])));
    }
}
