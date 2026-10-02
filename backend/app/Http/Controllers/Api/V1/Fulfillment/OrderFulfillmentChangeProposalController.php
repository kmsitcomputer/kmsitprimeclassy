<?php

namespace App\Http\Controllers\Api\V1\Fulfillment;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderFulfillmentChangeProposalResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderFulfillmentChangeProposal;
use App\Models\OrderItem;
use App\Services\Order\OrderFulfillmentChangeProposalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentChangeProposalController extends Controller
{
    public function __construct(private readonly OrderFulfillmentChangeProposalService $service) {}

    public function orders(Request $request)
    {
        abort_unless($request->user()->isRole('gudang'), 403);
        $orders = $this->service->ordersFor($request->user());
        return $this->ok(OrderResource::collection($orders)->resolve(), meta: ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isRole('admin'), 403);
        $rows = $this->service->proposalsFor($request->user(), $request->input('status', 'pending'));
        return $this->ok(OrderFulfillmentChangeProposalResource::collection($rows)->resolve(), meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]);
    }

    public function store(Request $request, Order $order, OrderItem $item)
    {
        $data = $request->validate(['fulfilled_quantity' => ['sometimes', 'required', 'integer', 'min:0'], 'requested_delivery_date' => ['sometimes', 'nullable', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);
        if (! array_key_exists('fulfilled_quantity', $data) && ! array_key_exists('requested_delivery_date', $data)) {
            throw ValidationException::withMessages(['requested_delivery_date' => 'Provide a fulfillment quantity, delivery date, or both.']);
        }
        if ($item->order_id !== $order->id) abort(404);
        return $this->created(new OrderFulfillmentChangeProposalResource($this->service->propose($request->user(), $order, $item, $data)));
    }

    public function decide(Request $request, OrderFulfillmentChangeProposal $proposal, string $decision)
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        return $this->ok(new OrderFulfillmentChangeProposalResource($this->service->decide($request->user(), $proposal, $decision === 'approve' ? 'approve' : 'reject', $data['reason'] ?? null)));
    }
}
