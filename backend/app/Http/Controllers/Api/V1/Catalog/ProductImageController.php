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
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    /**
     * REM-02: a gallery scope is (product, variation-or-null). The parent
     * gallery (variation null) and each variant's gallery are independent and
     * each has at most one primary image.
     */
    public function store(StoreProductImageRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $variationId = $request->integer('product_variation_id') ?: null;
        if ($variationId !== null && ! $product->variations()->whereKey($variationId)->exists()) {
            throw ValidationException::withMessages([
                'product_variation_id' => 'Varian bukan milik produk ini.',
            ]);
        }

        $path = $request->file('image')->store('products', 'public');

        try {
            $image = DB::transaction(function () use ($request, $product, $variationId, $path) {
                // Serialise gallery edits for this product so two concurrent
                // "make primary" requests cannot both win.
                Product::query()->whereKey($product->id)->lockForUpdate()->first();

                $isPrimary = $request->boolean('is_primary');
                if ($isPrimary) {
                    $this->scope($product, $variationId)->update(['is_primary' => false]);
                }

                return ProductImage::create([
                    'product_id' => $product->id,
                    'product_variation_id' => $variationId,
                    'path' => $path,
                    'is_primary' => $isPrimary,
                    'sort_order' => $request->integer('sort_order', 0),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        return $this->created(new ProductImageResource($image));
    }

    /** "Jadikan Utama" — only field an already-uploaded image ever needs changed after the fact. */
    public function update(Request $request, Product $product, ProductImage $image)
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);

        $validated = $request->validate(['is_primary' => ['required', 'boolean']]);

        DB::transaction(function () use ($product, $image, $validated) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if ($validated['is_primary']) {
                // Only the image's OWN gallery scope loses its primary.
                $this->scope($product, $image->product_variation_id)->where('id', '!=', $image->id)->update(['is_primary' => false]);
            }
            $image->update(['is_primary' => $validated['is_primary']]);
        });

        return $this->ok(new ProductImageResource($image->fresh()));
    }

    public function destroy(Product $product, ProductImage $image)
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);

        $path = $image->path;
        DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $variationId = $image->product_variation_id;
            $wasPrimary = (bool) $image->is_primary;
            $image->delete();
            // A gallery that still has images always keeps exactly one primary: removing the
            // primary promotes the first remaining image of THAT gallery scope only.
            if ($wasPrimary) {
                $next = $this->scope($product, $variationId)->orderBy('sort_order')->orderBy('id')->first();
                $next?->update(['is_primary' => true]);
            }
        });
        Storage::disk('public')->delete($path); // file cleanup only after the row is gone

        return $this->ok(null, __('messages.product.image_deleted'));
    }

    /**
     * REM-02 reorder: `ids` must be EXACTLY the images of one gallery scope
     * (product + variation-or-null), in the desired order. A partial/foreign/duplicate list is
     * rejected, so one variation's order can never touch another's images.
     */
    public function reorder(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'product_variation_id' => ['nullable', 'integer'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $variationId = isset($data['product_variation_id']) ? (int) $data['product_variation_id'] : null;
        if ($variationId !== null && ! $product->variations()->whereKey($variationId)->exists()) {
            throw ValidationException::withMessages(['product_variation_id' => 'Varian bukan milik produk ini.']);
        }

        DB::transaction(function () use ($product, $variationId, $data) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $existing = $this->scope($product, $variationId)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $given = collect($data['ids'])->map(fn ($i) => (int) $i)->sort()->values()->all();
            if ($existing !== $given) {
                throw ValidationException::withMessages(['ids' => 'Daftar gambar harus persis sama dengan seluruh gambar galeri ini.']);
            }
            foreach (array_values($data['ids']) as $position => $id) {
                $this->scope($product, $variationId)->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return $this->ok($this->scope($product, $variationId)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($i) => new ProductImageResource($i))->all());
    }

    private function scope(Product $product, ?int $variationId)
    {
        $q = ProductImage::query()->where('product_id', $product->id);

        return $variationId === null ? $q->whereNull('product_variation_id') : $q->where('product_variation_id', $variationId);
    }
}
