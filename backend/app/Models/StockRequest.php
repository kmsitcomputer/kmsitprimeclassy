<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockRequest extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'order_id', 'request_number', 'status', 'created_by', 'fulfilled_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'order_id' => 'integer', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(StockRequestFulfillment::class);
    }
}
