<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Support\SafeSchema;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
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

        $categories = ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get();

        return $this->ok(ProductCategoryResource::collection($categories)->resolve());
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $this->authorize('create', ProductCategory::class);

        $category = ProductCategory::create([
            ...$request->only('parent_id', 'is_active', 'sort_order'),
            'name' => $request->string('name'),
            'slug' => $request->filled('slug') ? $request->string('slug') : Str::slug($request->string('name')).'-'.uniqid(),
        ]);

        return $this->created(new ProductCategoryResource($category));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category)
    {
        $this->authorize('update', ProductCategory::class);

        $category->update($request->only('parent_id', 'name', 'slug', 'is_active', 'sort_order'));

        return $this->ok(new ProductCategoryResource($category));
    }

    public function destroy(ProductCategory $category)
    {
        $this->authorize('delete', ProductCategory::class);

        $category->delete();

        return $this->ok(null, __('messages.product.category_deleted'));
    }
}
