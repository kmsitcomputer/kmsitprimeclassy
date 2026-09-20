<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCancellationReversal extends Model
{
    protected $fillable = ['agent_id', 'order_id', 'order_item_id', 'stock_request_id', 'fulfilled_quantity', 'released_quantity', 'processed_by'];

    protected function casts(): array
    {
        return ['fulfilled_quantity' => 'integer', 'released_quantity' => 'integer'];
    }
}
