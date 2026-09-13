<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingConfiguration extends Model
{
    protected $fillable = [
        'agent_id', 'shipping_provider_id', 'price_per_km', 'minimum_distance_km',
        'minimum_charge', 'free_shipping_enabled', 'free_shipping_min_amount', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_per_km' => 'decimal:2',
            'minimum_distance_km' => 'decimal:2',
            'minimum_charge' => 'decimal:2',
            'free_shipping_min_amount' => 'decimal:2',
            'free_shipping_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }
}
