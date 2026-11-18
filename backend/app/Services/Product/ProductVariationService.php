<?php

namespace App\Services\Product;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductVariationAttribute;
use App\Models\ProductVariationAttributeOption;
use App\Models\ProductVariationComposition;
use Illuminate\Support\Facades\DB;

/**
 * Creates a sellable SKU (ProductVariation) from a product-scoped set of
 * attribute-name => option-value pairs (e.g. "Ukuran" => "1kg", "Rasa" =>
 * "Coklat"), reusing existing attribute/option rows for the product where
 * they already exist rather than duplicating them per variation.
 */
class ProductVariationService
{
    /** @param  array<string,string>  $attributes */
    public function create(Product $product, array $attributes, array $variationData): ProductVariation
    {
        if (! $product->has_variations) {
            throw new ApiException(
                __('messages.product.variation_not_enabled', ['name' => $product->name]),
                422
            );
        }

        if (empty($attributes)) {
            throw new ApiException(__('messages.product.variation_requires_attribute'), 422);
        }

        return DB::transaction(function () use ($product, $attributes, $variationData) {
            $variation = ProductVariation::create([
                'product_id' => $product->id,
                'sku' => $variationData['sku'],
                'price' => $variationData['price'],
                'weight_grams' => $variationData['weight_grams'],
                'is_active' => $variationData['is_active'] ?? true,
            ]);

            foreach ($attributes as $attributeName => $optionValue) {
                $attribute = ProductVariationAttribute::query()->firstOrCreate(
                    ['product_id' => $product->id, 'name' => $attributeName]
                );

                $option = ProductVariationAttributeOption::query()->firstOrCreate(
                    ['product_variation_attribute_id' => $attribute->id, 'value' => $optionValue]
                );

                ProductVariationComposition::create([
                    'product_variation_id' => $variation->id,
                    'product_variation_attribute_id' => $attribute->id,
                    'product_variation_attribute_option_id' => $option->id,
                ]);
            }

            return $variation->fresh();
        });
    }

    /**
     * REM-08: replace the attribute composition of ONE variation.
     *
     * Option rows are shared between sibling variations, so they are never
     * renamed in place — the target variation is re-pointed at (existing or
     * newly created) attribute/option rows. Siblings and historical order
     * snapshots (order_items.variation_label_snapshot) are untouched.
     *
     * @param  array<string,string>  $attributes
     */
    public function updateAttributes(Product $product, ProductVariation $variation, array $attributes): ProductVariation
    {
        if ($variation->product_id !== $product->id) {
            throw new ApiException('Varian bukan milik produk ini.', 404);
        }

        $clean = [];
        foreach ($attributes as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name === '' || $value === '' || mb_strlen($name) > 100 || mb_strlen($value) > 100) {
                throw new ApiException(__('messages.product.variation_requires_attribute'), 422);
            }
            if (isset($clean[mb_strtolower($name)])) {
                throw new ApiException('Nama atribut ganda.', 422);
            }
            $clean[mb_strtolower($name)] = [$name, $value];
        }
        if ($clean === []) {
            throw new ApiException(__('messages.product.variation_requires_attribute'), 422);
        }

        return DB::transaction(function () use ($product, $variation, $clean) {
            // Serialise composition edits of this product's variants.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $pairs = [];
            foreach ($clean as [$name, $value]) {
                $attribute = ProductVariationAttribute::query()->firstOrCreate(['product_id' => $product->id, 'name' => $name]);
                $option = ProductVariationAttributeOption::query()->firstOrCreate(
                    ['product_variation_attribute_id' => $attribute->id, 'value' => $value]
                );
                $pairs[$attribute->id] = $option->id;
            }

            // Two variants of one product may not carry the identical composition.
            $siblings = ProductVariationComposition::query()
                ->whereIn('product_variation_id', $product->variations()->where('id', '!=', $variation->id)->select('id'))
                ->get()->groupBy('product_variation_id');
            foreach ($siblings as $rows) {
                $map = $rows->pluck('product_variation_attribute_option_id', 'product_variation_attribute_id')->all();
                ksort($map);
                $mine = $pairs;
                ksort($mine);
                if ($map === $mine) {
                    throw new ApiException('Kombinasi atribut sudah dipakai varian lain pada produk ini.', 422);
                }
            }

            ProductVariationComposition::query()->where('product_variation_id', $variation->id)->delete();
            foreach ($pairs as $attributeId => $optionId) {
                ProductVariationComposition::create([
                    'product_variation_id' => $variation->id,
                    'product_variation_attribute_id' => $attributeId,
                    'product_variation_attribute_option_id' => $optionId,
                ]);
            }

            return $variation->fresh();
        });
    }
}
