<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    protected $fillable = ['stock_opname_id', 'product_id', 'product_variation_id', 'sub_location_id', 'system_stock_id', 'system_stock_revision', 'system_quantity', 'counted_quantity', 'difference', 'adjusted_quantity', 'import_row_key', 'import_fingerprint', 'import_target_quantity', 'pending_catalog_change', 'catalog_published_at'];

    protected function casts(): array
    {
        return ['system_stock_id' => 'integer', 'system_stock_revision' => 'integer', 'system_quantity' => 'integer', 'counted_quantity' => 'integer', 'difference' => 'integer', 'adjusted_quantity' => 'integer', 'import_target_quantity' => 'integer', 'pending_catalog_change' => 'array', 'catalog_published_at' => 'datetime'];
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
