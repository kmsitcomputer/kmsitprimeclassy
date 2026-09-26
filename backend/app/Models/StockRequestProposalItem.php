<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestProposalItem extends Model
{
    protected $fillable = ['stock_request_proposal_id', 'stock_request_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(StockRequestProposal::class, 'stock_request_proposal_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(StockRequestItem::class, 'stock_request_item_id');
    }
}
