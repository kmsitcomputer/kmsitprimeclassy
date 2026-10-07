<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMP-002 — voucher (promo code).
 *
 * Agent-scoped; applied at quote/order creation time by VoucherService
 * (never trusted from the frontend). `used_count` is incremented under a row
 * lock by VoucherService::consume so a limited voucher cannot overspend under
 * concurrency.
 */
class Voucher extends Model
{
    protected $fillable = [
        'agent_id', 'code', 'name', 'type', 'value', 'is_active',
        'valid_from', 'valid_until', 'product_id', 'product_variation_id',
        'max_uses', 'used_count',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'type' => 'string',
            'value' => 'decimal:2',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'product_id' => 'integer',
            'product_variation_id' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = today();
        if ($this->valid_from && $today->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $today->gt($this->valid_until)) {
            return false;
        }

        if ($this->max_uses !== null && (int) $this->used_count >= (int) $this->max_uses) {
            return false;
        }

        return true;
    }
}