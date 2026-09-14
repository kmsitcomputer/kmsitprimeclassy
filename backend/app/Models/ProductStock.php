<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'product_id', 'quantity_on_hand', 'quantity_reserved'];

    // See User::casts() — agent_id is compared with strict === against
    // other already-int agent_id values in Policies.
    protected function casts(): array
    {
        return ['agent_id' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function availableQuantity(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }
}
