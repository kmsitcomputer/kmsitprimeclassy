<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockTransfer extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'transfer_number', 'source_stock_type', 'source_sub_location_id', 'destination_stock_type', 'destination_sub_location_id', 'status', 'reference', 'note', 'created_by', 'completed_by', 'completed_at', 'rejected_by', 'rejected_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'source_sub_location_id' => 'integer', 'destination_sub_location_id' => 'integer', 'created_by' => 'integer', 'completed_by' => 'integer', 'completed_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function handover(): HasOne
    {
        return $this->hasOne(StockHandover::class);
    }

    public function sourceSubLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseSubLocation::class, 'source_sub_location_id');
    }

    public function destinationSubLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseSubLocation::class, 'destination_sub_location_id');
    }
}
