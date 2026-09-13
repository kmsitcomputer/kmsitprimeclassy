<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $path = $request->file('image')->store('products', 'public');

        $image = ProductImage::create([
            'product_id' => $product->id,
            'product_variation_id' => $request->integer('product_variation_id') ?: null,
            'path' => $path,
            'is_primary' => $request->boolean('is_primary'),
            'sort_order' => $request->integer('sort_order', 0),
        ]);

        return $this->created(new ProductImageResource($image));
    }

    /** "Jadikan Utama" — only field an already-uploaded image ever needs changed after the fact. */
    public function update(Request $request, Product $product, ProductImage $image)
    {
        $this->authorize('update', $product);

        $validated = $request->validate(['is_primary' => ['required', 'boolean']]);

        if ($validated['is_primary']) {
            DB::transaction(function () use ($product, $image) {
                ProductImage::query()->where('product_id', $product->id)->where('id', '!=', $image->id)->update(['is_primary' => false]);
                $image->update(['is_primary' => true]);
            });
        } else {
            $image->update(['is_primary' => false]);
        }

        return $this->ok(new ProductImageResource($image->fresh()));
    }

    public function destroy(Product $product, ProductImage $image)
    {
        $this->authorize('update', $product);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return $this->ok(null, __('messages.product.image_deleted'));
    }
}
