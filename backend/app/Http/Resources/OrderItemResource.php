<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variation_id' => $this->product_variation_id,
            'product_name' => $this->product_name_snapshot,
            'variation_label' => $this->variation_label_snapshot,
            'sku' => $this->sku_snapshot,
            'unit_price' => $this->unit_price_snapshot,
            'original_quantity' => $this->original_quantity,
            'fulfilled_quantity' => $this->fulfilled_quantity,
            'cancelled_quantity' => $this->cancelled_quantity,
            'returned_quantity' => $this->returned_quantity,
            'refund_quantity' => $this->refund_quantity,
            'additional_quantity' => $this->additional_quantity,
            'subtotal' => $this->subtotal_snapshot,
            'status' => $this->status,
            'requested_delivery_date' => $this->requested_delivery_date,
            'shipment_id' => $this->shipment_id,
            // Only present once THIS item's own shipment has a courier — a
            // rescheduled item can sit on a different shipment (and courier)
            // than its siblings on the same order (Blueprint: "satu order
            // bisa beberapa kurir").
            'courier' => $this->when(
                $this->relationLoaded('shipment') && $this->shipment?->courier,
                fn () => [
                    'name' => $this->shipment->courier->name,
                    'phone' => $this->shipment->courier->user?->phone,
                    // Not sensitive on its own — only used by the frontend to tell
                    // whether the shipment's current viewer IS this courier, so a
                    // kurir never sees another kurir's already-picked-up shipment
                    // rendered as actionable (e.g. a "mark delivered" button).
                    'user_id' => $this->shipment->courier->user_id,
                ]
            ),
            // "Kurir harus memasukan bukti pengiriman" — the delivery photo, once submitted.
            'delivery_proof_url' => $this->when(
                $this->relationLoaded('shipment') && $this->shipment?->proof,
                fn () => $this->shipment->proof->url()
            ),
            // Fee amounts are commission data, not customer-facing — each type
            // is gated to exactly the roles allowed to see it (Blueprint fee
            // visibility rules). agent_fee is the branch owner's own margin —
            // always agen/super_admin only, full stop, never admin/keuangan:
            // a konsumen referred directly by the agen (no sales in between)
            // earns the agen a SEPARATE sales_fee commission for that (see
            // OrderService::recordCommission) rather than exposing this one.
            'agent_fee_amount' => $this->when(
                $request->user()?->isRole('super_admin', 'agen') ?? false,
                $this->agent_fee_amount
            ),
            'sales_fee_amount' => $this->when(
                $request->user()?->isRole('super_admin', 'agen', 'sales', 'admin', 'keuangan') ?? false,
                $this->sales_fee_amount
            ),
            'courier_fee_amount' => $this->when(
                $request->user()?->isRole('super_admin', 'agen', 'admin', 'keuangan') ?? false,
                $this->courier_fee_amount
            ),
        ];
    }
}
