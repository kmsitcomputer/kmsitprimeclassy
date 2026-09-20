<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    protected $fillable = ['stock_opname_id', 'product_id', 'product_variation_id', 'sub_location_id', 'system_quantity', 'counted_quantity', 'difference', 'adjusted_quantity'];

    protected function casts(): array
    {
        return ['system_quantity' => 'integer', 'counted_quantity' => 'integer', 'difference' => 'integer', 'adjusted_quantity' => 'integer'];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }
}
