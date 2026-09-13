<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariationAttributeOption extends Model
{
    protected $fillable = ['product_variation_attribute_id', 'value', 'sort_order'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductVariationAttributeOptionTranslation::class);
    }
}
