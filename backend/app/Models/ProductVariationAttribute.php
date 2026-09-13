<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariationAttribute extends Model
{
    protected $fillable = ['product_id', 'name', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductVariationAttributeOption::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductVariationAttributeTranslation::class);
    }
}
