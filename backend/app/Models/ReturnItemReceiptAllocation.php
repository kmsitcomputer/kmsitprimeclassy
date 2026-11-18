<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** REM-07: append-only link between an accepted return item and the receipt event(s) its units came from. */
class ReturnItemReceiptAllocation extends Model
{
    protected $fillable = ['return_item_id', 'order_item_receipt_id', 'quantity', 'allocated_by', 'allocated_at', 'evidence_note'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'allocated_at' => 'datetime'];
    }
}
