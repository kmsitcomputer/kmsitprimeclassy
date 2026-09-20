<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'product_id', 'product_variation_id', 'stock_type', 'sub_location_id', 'quantity'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'sub_location_id' => 'integer', 'quantity' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseSubLocation::class, 'sub_location_id');
    }
}
