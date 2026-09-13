<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentShippingProviderConfig extends Model
{
    protected $fillable = ['agent_id', 'shipping_provider_id', 'config'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function shippingProvider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class);
    }
}
