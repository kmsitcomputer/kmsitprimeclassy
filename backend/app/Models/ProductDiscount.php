<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMP-002 — first-class product/variation discount.
 *
 * Targets a product OR a variation, scoped to one Agent. The percentage is
 * applied by PricingService against the canonical base price at quote/order
 * creation time; historical orders keep their own unit_price_snapshot and are
 * never recalculated.
 */
class ProductDiscount extends Model
{
    protected $fillable = [
        'agent_id', 'product_id', 'product_variation_id', 'name',
        'percentage', 'is_active', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'product_id' => 'integer',
            'product_variation_id' => 'integer',
            'percentage' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = today();
        if ($this->starts_at && $today->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $today->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}