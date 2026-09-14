<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Courier extends Model
{
    protected $fillable = ['type', 'user_id', 'agent_id', 'external_code', 'name', 'is_active'];

    protected function casts(): array
    {
        // See User::casts() — user_id is compared with strict !== against
        // $actor->id in CourierService::updateShipmentStatus (delivery-proof
        // ownership check); agent_id is compared with strict !== in
        // CourierService::assignCourier.
        return ['is_active' => 'boolean', 'user_id' => 'integer', 'agent_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
