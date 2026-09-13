<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariationComposition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_variation_id', 'product_variation_attribute_id', 'product_variation_attribute_option_id',
    ];

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductVariationAttributeOption::class, 'product_variation_attribute_option_id');
    }
}
