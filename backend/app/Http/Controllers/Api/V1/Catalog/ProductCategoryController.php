<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Product\CategoryHierarchyService;
use App\Support\SafeSchema;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function __construct(private readonly CategoryHierarchyService $hierarchy) {}

    /**
     * Public — needed to build catalog navigation/filters. Reachable on a
     * completely fresh, unmigrated deployment (installer wizard page, bots,
     * monitoring) before `product_categories` exists — SafeSchema treats
     * that identically to "no DB connection at all" and this degrades to an
     * empty list instead of a raw QueryException (see SafeSchema's docblock).
     */
    public function index()
    {
        if (! SafeSchema::hasTable('product_categories')) {
            return $this->ok([]);
        }

        $categories = ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return $this->ok(ProductCategoryResource::collection($categories)->resolve());
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $this->authorize('create', ProductCategory::class);

        $actor = $request->user();
        $attributes = [
            'parent_id' => $request->input('parent_id'),
            'name' => (string) $request->input('name'),
            // Always server-generated for create-only roles (no slug squatting).
            'slug' => Str::slug((string) $request->input('name')).'-'.uniqid(),
        ];
        // Presentation/ordering controls of the GLOBAL taxonomy stay with super_admin; create-only roles
        // cannot influence ordering or visibility, whatever their payload says.
        if ($actor->isRole('super_admin')) {
            $attributes += $request->only('is_active', 'sort_order');
            if ($request->filled('slug')) {
                $attributes['slug'] = (string) $request->input('slug');
            }
        }

        $category = $this->hierarchy->create($attributes, $actor);

        return $this->created(new ProductCategoryResource($category));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category)
    {
        $this->authorize('update', ProductCategory::class);

        $category = $this->hierarchy->update($category, $request->only('parent_id', 'name', 'slug', 'is_active', 'sort_order'));

        return $this->ok(new ProductCategoryResource($category));
    }

    public function destroy(ProductCategory $category)
    {
        $this->authorize('delete', ProductCategory::class);

        $this->hierarchy->delete($category);

        return $this->ok(null, __('messages.product.category_deleted'));
    }
}
