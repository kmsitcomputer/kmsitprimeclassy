<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Api\V1\Catalog\Concerns\AttachesAgentAvailability;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Product\CategoryHierarchyService;
use App\Services\Stock\WarehouseStockService;
use Illuminate\Http\Request;

/**
 * REM-03 / REM-05: management catalog reads. Unlike the public storefront
 * listing (active only, primary image only, active variations only) these
 * return every status and the COMPLETE image/variant set, so the edit form can
 * never lose or duplicate an asset because the read model was filtered.
 */
class ProductManagementController extends Controller
{
    use AttachesAgentAvailability;

    /** "all" is a UI filter value, never a database status. */
    public const STATUS_FILTERS = ['all', 'active', 'draft', 'inactive'];

    public function __construct(
        private readonly WarehouseStockService $warehouseStockService,
        private readonly CategoryHierarchyService $hierarchy,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('manage', Product::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUS_FILTERS)],
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'all';

        $query = Product::query()
            ->with($this->relations())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($validated['category_id']), fn ($q) => $q->whereIn(
                'category_id', $this->hierarchy->selfAndDescendantIds((int) $validated['category_id'])
            ))
            ->when(! empty($validated['search']), function ($q) use ($validated) {
                $term = '%'.addcslashes($validated['search'], '%_\\').'%';
                $q->where(fn ($qq) => $qq->where('name', 'like', $term)->orWhere('sku', 'like', $term)
                    ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $term)));
            })
            ->orderByDesc('id');

        $products = $query->paginate((int) ($validated['per_page'] ?? 25));
        $this->attachAgentAvailability($request, collect($products->items()));

        return $this->ok(
            ProductResource::collection($products)->resolve(),
            meta: [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
                'status_filter' => $status,
            ]
        );
    }

    public function show(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $product->load($this->relations());
        $this->attachAgentAvailability($request, collect([$product]));

        return $this->ok(new ProductResource($product));
    }

    /** @return array<string,mixed> */
    private function relations(): array
    {
        return [
            'category',
            'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'variations' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'variations.compositions.attribute',
            'variations.compositions.option',
        ];
    }
}
