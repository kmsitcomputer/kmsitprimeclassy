<?php

namespace App\Models;

use App\Models\Concerns\HasGlobalSku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasGlobalSku, SoftDeletes;

    protected $fillable = [
        'category_id', 'created_by', 'name', 'slug', 'description', 'short_description',
        'sku', 'has_variations', 'base_price', 'weight_grams', 'status',
    ];

    protected function casts(): array
    {
        return [
            'has_variations' => 'boolean',
            'base_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attributes_(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function variationAttributes(): HasMany
    {
        return $this->hasMany(ProductVariationAttribute::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function fee(): HasMany
    {
        return $this->hasMany(ProductFee::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }
}
