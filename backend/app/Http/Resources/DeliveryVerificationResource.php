<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * R-03: one append-only Admin delivery-verification record. `outcome` is the operational delivery
 * outcome only — never a payment/transaction state.
 */
class DeliveryVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'outcome' => $this->outcome,
            'note' => $this->note,
            'verified_at' => $this->verified_at,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy ? [
                'id' => $this->verifiedBy->id,
                'name' => $this->verifiedBy->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
