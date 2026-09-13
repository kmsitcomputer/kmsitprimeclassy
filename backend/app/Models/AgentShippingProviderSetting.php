<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentShippingProviderSetting extends Model
{
    protected $fillable = ['agent_id', 'shipping_provider_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
