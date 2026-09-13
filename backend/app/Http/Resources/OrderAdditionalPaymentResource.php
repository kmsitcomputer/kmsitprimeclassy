<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Powers the Admin "Additional Payment" dashboard menu. */
class OrderAdditionalPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_no' => $this->order->order_no,
            'customer_name' => $this->order->konsumen->name,
            'method' => $this->method,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => $this->requestedBy?->name,
            'payment_instructions' => $this->whenLoaded('paymentTransaction', fn () => $this->paymentTransaction?->raw_payload),
            'created_at' => $this->created_at,
        ];
    }
}
