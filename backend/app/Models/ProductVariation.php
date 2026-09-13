<?php

namespace App\Models;

use App\Models\Concerns\HasGlobalSku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    use HasGlobalSku, SoftDeletes;

    protected $fillable = ['product_id', 'sku', 'price', 'weight_grams', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function compositions(): HasMany
    {
        return $this->hasMany(ProductVariationComposition::class);
    }

    public function fee(): HasMany
    {
        return $this->hasMany(ProductVariationFee::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductVariationStock::class);
    }

    /** Human-readable label built from its composed options, e.g. "1kg / Coklat". */
    public function label(): string
    {
        return $this->compositions()
            ->with('option')
            ->get()
            ->pluck('option.value')
            ->implode(' / ');
    }
}
