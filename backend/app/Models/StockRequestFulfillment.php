<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestFulfillment extends Model
{
    protected $fillable = ['stock_request_id', 'idempotency_key', 'fulfilled_by'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }
}
