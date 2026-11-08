<?php

namespace App\Http\Resources;

use App\Models\Shipment;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * COURIER-002 — one DELIVERED shipment in the Koordinator-Kurir history. Operational only:
 * identity, executor attribution, completion time, destination region and a proof reference.
 * Deliberately no payment/DP/outstanding/refund/commission fields and no street address or phone.
 */
class DispatchHistoryResource extends JsonResource
{
    public function toArray($request)
    {
        /** @var Shipment $shipment */
        $shipment = $this->resource;

        if ($shipment->isSelfDelivery()) {
            $executor = $shipment->selfDeliveredBy
                ? ['type' => 'sks', 'name' => $shipment->selfDeliveredBy->name]
                : ['type' => 'unavailable', 'name' => null];
        } elseif ($shipment->courier) {
            $executor = ['type' => 'courier', 'name' => $shipment->courier->name, 'courier_id' => $shipment->courier->id];
        } else {
            $executor = ['type' => 'unavailable', 'name' => null];
        }

        return [
            'id' => $shipment->id,
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'order_no' => $shipment->order?->order_no,
            'status' => $shipment->status,
            'delivery_mode' => $shipment->delivery_mode,
            'executor' => $executor,
            'delivered_at' => $shipment->delivered_at?->toISOString(),
            'recipient_name' => $shipment->order?->recipient_name_snapshot,
            'village_name' => $shipment->order?->village_snapshot,
            'district_name' => $shipment->order?->district_snapshot,
            'regency_name' => $shipment->order?->regency_snapshot,
            'province_name' => $shipment->order?->province_snapshot,
            'proof_available' => $shipment->proof_media_id !== null,
            'proof_url' => $shipment->proof?->url(),
        ];
    }
}
