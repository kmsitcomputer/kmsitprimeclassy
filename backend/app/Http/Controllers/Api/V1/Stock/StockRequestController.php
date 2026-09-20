<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockRequestResource;
use App\Models\StockRequest;
use App\Services\Stock\StockRequestFulfillmentService;
use Illuminate\Http\Request;

class StockRequestController extends Controller
{
    public function __construct(private readonly StockRequestFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        $requests = StockRequest::query()->with(['items.product.images', 'items.variation.compositions.option'])->latest()->paginate($request->integer('per_page', 15));

        return $this->ok(StockRequestResource::collection($requests)->resolve(), meta: [
            'current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total(),
        ]);
    }

    public function show(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('view', $stockRequest);

        return $this->ok(new StockRequestResource($stockRequest->load(['items.product.images', 'items.variation.compositions.option'])));
    }

    public function fulfill(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('fulfill', $stockRequest);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'], 'items' => ['required', 'array', 'min:1'], 'items.*.item_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'integer', 'min:1']]);

        return $this->ok(new StockRequestResource($this->fulfillment->fulfill($request->user(), $stockRequest, $data['items'], $data['idempotency_key'])->load(['items.product.images', 'items.variation.compositions.option'])));
    }
}
