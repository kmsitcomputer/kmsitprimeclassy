<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockRequestResource;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockRequest;
use App\Models\WarehouseStock;
use App\Services\Stock\StockRequestFulfillmentService;
use Illuminate\Http\Request;

class StockRequestController extends Controller
{
    public function __construct(private readonly StockRequestFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('super_admin', 'agen', 'admin', 'gudang')) {
            abort(403);
        }

        $requests = StockRequest::query()
            ->when(! $actor->isRole('super_admin'), fn ($q) => $q->where('agent_id', $actor->agent_id))
            ->with(['items.product.images', 'items.variation.compositions.option', 'items.orderItem', 'order'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search')->toString().'%';
                $q->where(function ($qq) use ($search) {
                    $qq->where('request_number', 'like', $search)
                        ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', $search))
                        ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', $search)->orWhere('sku', 'like', $search))
                        ->orWhereHas('items.variation', fn ($v) => $v->where('sku', 'like', $search));
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        $collection = $requests->getCollection();
        $collection->each(function ($row) use ($actor) {
            $row->items->each(fn ($item) => $item->setAttribute('current_stock', $this->currentBuckets($actor->agent_id, $item)));
        });
        $requests->setCollection($collection);

        return $this->ok(StockRequestResource::collection($requests)->resolve(), meta: [
            'current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total(),
        ]);
    }

    public function show(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('view', $stockRequest);
        $stockRequest->load(['items.product.images', 'items.variation.compositions.option', 'items.orderItem', 'order']);
        $stockRequest->items->each(fn ($item) => $item->setAttribute('current_stock', $this->currentBuckets($request->user()->agent_id, $item)));

        return $this->ok(new StockRequestResource($stockRequest));
    }

    private function currentBuckets(int $agentId, $item): array
    {
        $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))
            ->value('quantity');
        $shipping = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'shipping')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))
            ->value('quantity');
        $reserved = (int) ($item->product_variation_id
            ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $item->product_variation_id)->value('quantity_reserved')
            : ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $item->product_id)->value('quantity_reserved'));

        return ['transit' => $transit, 'shipping' => $shipping, 'reserved' => $reserved];
    }

    public function fulfill(Request $request, StockRequest $stockRequest)
    {
        $this->authorize('fulfill', $stockRequest);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'], 'items' => ['required', 'array', 'min:1'], 'items.*.item_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'integer', 'min:1']]);

        return $this->ok(new StockRequestResource($this->fulfillment->fulfill($request->user(), $stockRequest, $data['items'], $data['idempotency_key'])->load(['items.product.images', 'items.variation.compositions.option'])));
    }
}
