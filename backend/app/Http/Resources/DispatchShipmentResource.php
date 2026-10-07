<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * IMP-003 — operational dispatch row.
 *
 * Deliberately NOT the financial OrderResource: a dispatch row carries
 * shipment/order identity + destination + delivery date + a coarse
 * `paid_in_full` boolean (the koordinator's planning signal only), never
 * payment amounts or commissions. This keeps the financial projection
 * boundary intact — dispatch is an operational surface.
 */
class DispatchShipmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->id,
            'order_id' => $this->order_id,
            'order_no' => $this->order->order_no,
            // The ORDER's status ('diproses' — the dispatch state). Shipment
            // fulfillment state (pending/delivered) stays internal to the
            // kurir flow; dispatch rows are pre-pickup by definition.
            'status' => $this->order->status,
            'delivery_mode' => $this->delivery_mode,
            'self_delivered_by_user_id' => $this->self_delivered_by_user_id,
            'courier_id' => $this->courier_id,
            // A1-01: a Koordinator-Kurir may assign this shipment to THEMSELVES
            // (becoming the actual executor) — a canonical capability flag for the
            // frontend self-assignment button. Never a financial or other-branch
            // signal.
            'self_assignable' => $this->delivery_mode !== \App\Models\Shipment::DELIVERY_MODE_SELF_SUB,
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                'id' => $this->courier->id, 'name' => $this->courier->name,
            ] : null),
            // Destination snapshots (IMP-002 structured region ids on the order).
            'recipient_name' => $this->order->recipient_name_snapshot,
            'recipient_phone' => $this->order->recipient_phone_snapshot,
            'address_line' => $this->order->address_snapshot,
            'village_name' => $this->order->village_snapshot,
            'district_name' => $this->order->district_snapshot,
            'regency_name' => $this->order->regency_snapshot,
            'province_name' => $this->order->province_snapshot,
            'province_id' => $this->order->province_id,
            'regency_id' => $this->order->regency_id,
            'district_id' => $this->order->district_id,
            'village_id' => $this->order->village_id,
            // Requested delivery date (order items may spread across dates).
            'delivery_date' => $this->order->items
                ->where('shipment_id', $this->id)
                ->pluck('requested_delivery_date')
                ->filter()
                ->map(fn ($d) => $d instanceof \Carbon\CarbonInterface ? $d->toDateString() : (string) $d)
                ->unique()
                ->values(),
            // UAT-005: the canonical items riding THIS shipment — product +
            // quantity + requested delivery date + fulfillment status, so a
            // dispatch card is never an ambiguous order-level duplicate.
            // Items are read off the already-loaded order items (no new
            // query); grouping stays shipment-truth, never a new batch
            // subsystem.
            'items' => $this->order->items
                ->where('shipment_id', $this->id)
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name_snapshot,
                    'variation_label' => $item->variation_label_snapshot,
                    'sku' => $item->sku_snapshot,
                    'quantity' => (int) $item->fulfilled_quantity,
                    'status' => $item->status,
                    'requested_delivery_date' => $item->requested_delivery_date instanceof \Carbon\CarbonInterface
                        ? $item->requested_delivery_date->toDateString()
                        : ($item->requested_delivery_date !== null ? (string) $item->requested_delivery_date : null),
                ]),
            // Coarse planning signal only — NEVER a financial amount.
            'paid_in_full' => $this->order->isFullyPaid(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}