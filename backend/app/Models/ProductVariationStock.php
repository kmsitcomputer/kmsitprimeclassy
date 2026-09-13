<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariationStock extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'product_variation_id', 'quantity_on_hand', 'quantity_reserved'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function availableQuantity(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }
}
