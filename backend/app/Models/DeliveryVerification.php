<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * R-03: append-only Admin final delivery verification record (decision B).
 *
 * One row per verification action. A later action inserts a NEW row rather than mutating the
 * previous one, so `not_received -> received` remains visible as history. The current operational
 * outcome is simply the latest row for a shipment. Payment/transaction verification are separate
 * concerns and are never modelled here.
 */
class DeliveryVerification extends Model
{
    public const RECEIVED = 'received';

    public const NOT_RECEIVED = 'not_received';

    public const RETURN = 'return';

    protected $fillable = [
        'shipment_id', 'outcome', 'note', 'verified_by', 'verified_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** The Admin who recorded this verification — never erased (Users are soft-deleted). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
