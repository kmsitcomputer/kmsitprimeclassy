<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductVariationRequest;
use App\Http\Requests\Catalog\UpdateProductVariationRequest;
use App\Http\Resources\ProductVariationResource;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Product\ProductVariationService;

class ProductVariationController extends Controller
{
    public function __construct(private readonly ProductVariationService $variationService) {}

    public function store(StoreProductVariationRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $variation = $this->variationService->create(
            $product,
            $request->array('attributes'),
            $request->only('sku', 'price', 'weight_grams', 'is_active'),
        );

        return $this->created(new ProductVariationResource($variation));
    }

    public function update(UpdateProductVariationRequest $request, Product $product, ProductVariation $variation)
    {
        $this->authorize('update', $product);

        $variation->update($request->only('sku', 'price', 'weight_grams', 'is_active'));

        return $this->ok(new ProductVariationResource($variation));
    }

    public function destroy(Product $product, ProductVariation $variation)
    {
        $this->authorize('delete', $product);

        $variation->delete();

        return $this->ok(null, __('messages.product.variation_deleted'));
    }
}
