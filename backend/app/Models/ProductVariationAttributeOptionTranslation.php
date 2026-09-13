<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariationAttributeOptionTranslation extends Model
{
    protected $fillable = ['product_variation_attribute_option_id', 'language_id', 'value'];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductVariationAttributeOption::class, 'product_variation_attribute_option_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
