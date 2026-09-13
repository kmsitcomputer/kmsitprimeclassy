<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'beneficiary_user_id' => $this->beneficiary_user_id,
            'beneficiary_role' => $this->beneficiary_role,
            'amount' => $this->amount,
            'status' => $this->status,
            'earned_at' => $this->earned_at,
            'paid_at' => $this->paid_at,
        ];
    }
}
