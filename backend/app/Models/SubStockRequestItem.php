<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubStockRequestItem extends Model
{
    protected $fillable = ['sub_stock_request_id', 'product_id', 'product_variation_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
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
