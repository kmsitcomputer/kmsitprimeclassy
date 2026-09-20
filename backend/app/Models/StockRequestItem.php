<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestItem extends Model
{
    protected $fillable = ['stock_request_id', 'order_item_id', 'product_id', 'product_variation_id', 'sku_snapshot', 'requested_qty', 'fulfilled_qty', 'remaining_qty'];

    protected function casts(): array
    {
        return ['requested_qty' => 'integer', 'fulfilled_qty' => 'integer', 'remaining_qty' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
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
