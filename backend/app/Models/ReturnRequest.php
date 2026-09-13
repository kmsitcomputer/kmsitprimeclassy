<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Maps to the `returns` table — named ReturnRequest because Return is a reserved word. */
class ReturnRequest extends Model
{
    protected $table = 'returns';

    protected $fillable = [
        'order_id', 'requested_by', 'reason', 'evidence_path', 'status',
        'reviewed_by', 'reviewed_at', 'total_refund_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_refund_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
