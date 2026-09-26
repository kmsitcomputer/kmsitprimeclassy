<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseStockRequestResource;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockRequest;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\WarehouseStockRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WarehouseStockRequestController extends Controller
{
    public function __construct(private readonly WarehouseStockRequestService $requests) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('admin', 'gudang')) {
            abort(403);
        }

        $requests = WarehouseStockRequest::query()
            ->where('agent_id', $actor->agent_id)
            ->with(['product.images', 'variation.compositions.option', 'requester', 'subLocation'])
            ->when($request->string('scope')->toString() === 'mine', fn ($q) => $q->where('requested_by', $actor->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('request_type'), fn ($q) => $q->where('request_type', $request->string('request_type')->toString()))
            ->when($request->filled('sub_location_id'), fn ($q) => $q->where('sub_location_id', $request->integer('sub_location_id')))
            ->when($request->filled('target_stock_type'), fn ($q) => $q->where('target_stock_type', $request->string('target_stock_type')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search')->toString().'%';
                $q->where(function ($qq) use ($search) {
                    $qq->whereHas('product', fn ($p) => $p->where('name', 'like', $search)->orWhere('sku', 'like', $search))
                        ->orWhereHas('variation', fn ($v) => $v->where('sku', 'like', $search));
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        $collection = $requests->getCollection();
        $collection->each(fn ($row) => $row->setAttribute('current_stock_quantity', $this->currentStock($actor->agent_id, $row)));
        $requests->setCollection($collection);

        return $this->ok(WarehouseStockRequestResource::collection($requests)->resolve(), meta: [
            'current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total(),
        ]);
    }

    public function show(Request $request, WarehouseStockRequest $warehouseStockRequest)
    {
        $this->authorize('view', $warehouseStockRequest);
        $warehouseStockRequest->load(['product.images', 'variation.compositions.option', 'requester']);
        $warehouseStockRequest->setAttribute('current_stock_quantity', $this->currentStock($request->user()->agent_id, $warehouseStockRequest));

        return $this->ok(new WarehouseStockRequestResource($warehouseStockRequest));
    }

    public function store(Request $request)
    {
        $this->authorize('create', WarehouseStockRequest::class);
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'target_stock_type' => ['required', 'string', 'in:transit,factory_plan'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $stockRequest = $this->requests->create(
            $request->user(),
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            $data['variation_id'] ?? null,
            $data['quantity'],
            $data['target_stock_type'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
        );

        return $this->created(new WarehouseStockRequestResource($stockRequest));
    }

    public function storeSubAdjustment(Request $request)
    {
        $this->authorize('create', WarehouseStockRequest::class);
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'sub_location_id' => ['required', 'integer', 'exists:warehouse_sub_locations,id'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $stockRequest = $this->requests->createSubAdjustment(
            $request->user(),
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            $data['variation_id'] ?? null,
            $data['sub_location_id'],
            $data['delta'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
        );

        return $this->created(new WarehouseStockRequestResource($stockRequest));
    }

    public function approve(Request $request, WarehouseStockRequest $warehouseStockRequest)
    {
        $this->authorize('approve', $warehouseStockRequest);

        return $this->ok(new WarehouseStockRequestResource($this->requests->approve($request->user(), $warehouseStockRequest)));
    }

    public function reject(Request $request, WarehouseStockRequest $warehouseStockRequest)
    {
        $this->authorize('reject', $warehouseStockRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(new WarehouseStockRequestResource($this->requests->reject($request->user(), $warehouseStockRequest, $data['reason'])));
    }

    public function subCards(Request $request, WarehouseSubLocation $subLocation)
    {
        $actor = $request->user();
        if (! $actor->isRole('admin', 'gudang')) {
            abort(403);
        }
        if ($subLocation->agent_id !== $actor->agent_id) {
            abort(404);
        }
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $products = Product::query()
            ->where('status', 'active')
            ->with(['images', 'variations' => fn ($q) => $q->where('is_active', true)->with('compositions.option')])
            ->when(! empty($data['search']), function ($q) use ($data) {
                $term = '%'.$data['search'].'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', $term)->orWhere('sku', 'like', $term)
                        ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $term));
                });
            })
            ->paginate($data['per_page'] ?? 12);

        $agentId = $actor->agent_id;
        $rows = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->where('stock_type', 'sub')->where('sub_location_id', $subLocation->id)->get();

        $cards = $products->getCollection()->flatMap(function ($product) use ($rows) {
            $image = $product->images->firstWhere('is_primary', true) ?? $product->images->sortBy('sort_order')->first();
            $imageUrl = $image?->path ? Storage::disk('public')->url($image->path) : null;
            if (! $product->has_variations) {
                return [[
                    'product_id' => $product->id, 'product_variation_id' => null,
                    'product_name' => $product->name, 'variation_label' => null,
                    'sku' => $product->sku, 'product_image_url' => $imageUrl,
                    'sub_stock' => (int) $rows->where('product_id', $product->id)->whereNull('product_variation_id')->sum('quantity'),
                ]];
            }

            return $product->variations->map(fn ($variation) => [
                'product_id' => null, 'product_variation_id' => $variation->id,
                'product_name' => $product->name,
                'variation_label' => $variation->compositions->pluck('option.value')->filter()->implode(' / ') ?: null,
                'sku' => $variation->sku, 'product_image_url' => $imageUrl,
                'sub_stock' => (int) $rows->where('product_variation_id', $variation->id)->sum('quantity'),
            ])->all();
        });

        return $this->ok(array_values($cards->all()), meta: [
            'current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total(),
            'per_page' => $products->perPage(),
        ]);
    }

    public function cards(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('admin', 'gudang')) {
            abort(403);
        }
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $products = Product::query()
            ->where('status', 'active')
            ->with(['images', 'variations' => fn ($q) => $q->where('is_active', true)->with('compositions.option')])
            ->when(! empty($data['search']), function ($q) use ($data) {
                $term = '%'.$data['search'].'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', $term)->orWhere('sku', 'like', $term)
                        ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $term));
                });
            })
            ->paginate($data['per_page'] ?? 12);

        $agentId = $actor->agent_id;
        $rows = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->whereIn('stock_type', ['transit', 'factory_plan'])->get();
        $reserveSimple = ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->pluck('quantity_reserved', 'product_id');
        $reserveVar = ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->pluck('quantity_reserved', 'product_variation_id');
        $planEnabled = (bool) (WarehouseSetting::query()->where('agent_id', $agentId)->value('factory_plan_enabled') ?? false);

        $cards = $products->getCollection()->flatMap(function ($product) use ($rows, $reserveSimple, $reserveVar, $planEnabled) {
            $image = $product->images->firstWhere('is_primary', true) ?? $product->images->sortBy('sort_order')->first();
            $imageUrl = $image?->path ? Storage::disk('public')->url($image->path) : null;
            if (! $product->has_variations) {
                $transit = (int) $rows->where('product_id', $product->id)->whereNull('product_variation_id')->where('stock_type', 'transit')->sum('quantity');
                $plan = (int) $rows->where('product_id', $product->id)->whereNull('product_variation_id')->where('stock_type', 'factory_plan')->sum('quantity');
                $reserved = (int) ($reserveSimple[$product->id] ?? 0);

                return [[
                    'product_id' => $product->id, 'product_variation_id' => null,
                    'product_name' => $product->name, 'variation_label' => null,
                    'sku' => $product->sku, 'product_image_url' => $imageUrl,
                    'transit' => $transit, 'factory_plan' => $plan, 'reserved' => $reserved,
                    'sellable' => max(0, $transit + ($planEnabled ? $plan : 0) - $reserved),
                    'factory_plan_enabled' => $planEnabled,
                ]];
            }

            return $product->variations->map(fn ($variation) => [
                'product_id' => null, 'product_variation_id' => $variation->id,
                'product_name' => $product->name,
                'variation_label' => $variation->compositions->pluck('option.value')->filter()->implode(' / ') ?: null,
                'sku' => $variation->sku, 'product_image_url' => $imageUrl,
                'transit' => (int) $rows->where('product_variation_id', $variation->id)->where('stock_type', 'transit')->sum('quantity'),
                'factory_plan' => (int) $rows->where('product_variation_id', $variation->id)->where('stock_type', 'factory_plan')->sum('quantity'),
                'reserved' => (int) ($reserveVar[$variation->id] ?? 0),
                'sellable' => max(0, (int) $rows->where('product_variation_id', $variation->id)->where('stock_type', 'transit')->sum('quantity') + ($planEnabled ? (int) $rows->where('product_variation_id', $variation->id)->where('stock_type', 'factory_plan')->sum('quantity') : 0) - (int) ($reserveVar[$variation->id] ?? 0)),
                'factory_plan_enabled' => $planEnabled,
            ])->all();
        });

        return $this->ok(array_values($cards->all()), meta: [
            'current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total(),
            'per_page' => $products->perPage(),
        ]);
    }

    private function currentStock(int $agentId, WarehouseStockRequest $row): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)
            ->where('stock_type', $row->target_stock_type)
            ->when($row->target_stock_type === 'sub', fn ($q) => $q->where('sub_location_id', $row->sub_location_id))
            ->when($row->product_id, fn ($q) => $q->where('product_id', $row->product_id)->whereNull('product_variation_id'))
            ->when($row->product_variation_id, fn ($q) => $q->where('product_variation_id', $row->product_variation_id)->whereNull('product_id'))
            ->value('quantity');
    }
}
