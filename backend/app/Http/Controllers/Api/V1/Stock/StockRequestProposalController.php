<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockRequestProposalResource;
use App\Models\StockRequest;
use App\Models\StockRequestProposal;
use App\Services\Stock\StockRequestProposalService;
use Illuminate\Http\Request;

class StockRequestProposalController extends Controller
{
    public function __construct(private readonly StockRequestProposalService $proposals) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('admin', 'gudang', 'koordinator-kurir')) {
            abort(403);
        }

        $proposals = StockRequestProposal::query()
            ->where('agent_id', $actor->agent_id)
            ->with(['items.requestItem.product.images', 'items.requestItem.variation.compositions.option', 'requester', 'request.order', 'handoverProof'])
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

        return $this->ok(new StockRequestProposalResource($proposal->load(['items.requestItem.product.images', 'items.requestItem.variation.compositions.option', 'requester', 'request.order', 'handoverProof'])));
    }

    /**
     * Gudang approves what it can hand over for the Koordinator Kurir's request, records the reason for anything
     * short, and attaches the handover evidence (required). Multipart: `items[i][item_id|quantity|shortage_reason]`,
     * `shortages[i][item_id|reason]` (lines with nothing to hand over), `proof` (image).
     */
    public function store(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('propose', StockRequestProposal::class);
        $data = $request->validate([
            'items' => ['required_without:shortages', 'nullable', 'array'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.shortage_reason' => ['nullable', 'string', 'max:255'],
            'shortages' => ['nullable', 'array'],
            'shortages.*.item_id' => ['required', 'integer'],
            'shortages.*.reason' => ['required', 'string', 'min:3', 'max:255'],
            // Evidence accompanies goods that are actually handed over; a report of "nothing available" has none.
            'proof' => [empty($request->input('items')) ? 'nullable' : 'required', 'file', 'image', 'max:5120'],
        ]);

        if (empty($data['items'])) {
            return $this->created(['shortages' => $this->proposals->reportShortageOnly($request->user(), $stockRequest, $data['shortages'] ?? [])]);
        }

        $proposal = $this->proposals->propose($request->user(), $stockRequest, $data['items'], $data['shortages'] ?? [], $request->file('proof'));

        return $this->created(new StockRequestProposalResource($proposal->load('handoverProof')));
    }

    public function approve(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('approve', $proposal);
        // F07: Admin approval is the EXCEPTION to the Koordinator receipt — a reason is mandatory and audited.
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->ok(new StockRequestProposalResource($this->proposals->approve($request->user(), $proposal, $data['reason'])));
    }

    /** REM-002: Koordinator Kurir physical receipt — accepted / short / damaged per prepared item. */
    public function receive(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('receive', $proposal);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.accepted' => ['required', 'integer', 'min:0'],
            'items.*.short' => ['required', 'integer', 'min:0'],
            'items.*.damaged' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok(new StockRequestProposalResource(
            $this->proposals->receive($request->user(), $proposal, $data['items'], $data['note'] ?? null)
        ));
    }

    /** F07: audited Admin write-off; restock awaits an approved inspection workflow. */
    public function resolveDiscrepancy(Request $request, \App\Models\StockRequestItem $item)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'action' => ['required', 'in:restock,write_off'], 'quantity' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $resolved = $this->proposals->resolveDiscrepancy($request->user(), $item, $data['action'], (int) $data['quantity'], $data['reason'], $data['idempotency_key']);

        return $this->ok(['id' => $resolved->id, 'damaged_hold_qty' => (int) $resolved->damaged_hold_qty, 'remaining_qty' => (int) $resolved->remaining_qty]);
    }

    public function reject(Request $request, StockRequestProposal $proposal)
    {
        $this->authorize('reject', $proposal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(new StockRequestProposalResource($this->proposals->reject($request->user(), $proposal, $data['reason'])));
    }
}
