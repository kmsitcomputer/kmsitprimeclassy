<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'agent_id', 'product_id', 'product_variation_id', 'type', 'quantity', 'stock_type', 'sub_location_id',
        'counterpart_stock_type', 'transfer_id', 'handover_id', 'opname_id', 'warehouse_stock_request_id',
        'reference_type', 'reference_id', 'note', 'created_by',
    ];

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id');
    }
}
