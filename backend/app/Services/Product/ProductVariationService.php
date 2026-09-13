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
}
