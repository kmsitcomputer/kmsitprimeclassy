<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** R-02: a Sales-Kurir-Sub's request to move stock Transit -> Sub (replenish) or Sub -> Transit (return). */
class SubStockRequest extends Model
{
    public const REPLENISH = 'replenish';

    public const RETURN = 'return';

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = [
        'agent_id', 'sub_location_id', 'request_number', 'direction', 'status', 'requested_by', 'idempotency_key', 'note',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_at',
        'executed_by', 'executed_at', 'stock_transfer_id', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer', 'sub_location_id' => 'integer', 'requested_by' => 'integer', 'stock_transfer_id' => 'integer',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'executed_at' => 'datetime', 'received_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubStockRequestItem::class);
    }

    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseSubLocation::class, 'sub_location_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }
}
