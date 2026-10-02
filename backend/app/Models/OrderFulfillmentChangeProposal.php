<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFulfillmentChangeProposal extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = [
        'agent_id', 'order_id', 'order_item_id', 'proposed_by',
        'base_fulfilled_quantity', 'proposed_fulfilled_quantity',
        'base_requested_delivery_date', 'proposed_requested_delivery_date',
        'reason', 'status', 'decided_by', 'decided_at', 'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer', 'order_id' => 'integer', 'order_item_id' => 'integer',
            'proposed_by' => 'integer', 'base_fulfilled_quantity' => 'integer',
            'proposed_fulfilled_quantity' => 'integer', 'base_requested_delivery_date' => 'date',
            'proposed_requested_delivery_date' => 'date', 'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    public function proposer(): BelongsTo { return $this->belongsTo(User::class, 'proposed_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}