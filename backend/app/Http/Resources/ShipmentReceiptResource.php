<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thermal shipping-receipt payload — read-only, printable before and after
 * courier pickup. "Pickup" has no dedicated timestamp column in this
 * project; it is the same event as the diproses->dikirim transition, whose
 * authoritative timestamp is Shipment.shipped_at (see CourierService::
 * syncShipmentProgress). mode is therefore derived, never accepted from the
 * caller: 'post_pickup' once shipped_at is set, 'pre_pickup' until then.
 *
 * Deliberately excludes: fee/commission amounts (agent/sales/korsal/courier
 * fee), payment gateway/bank internals, ORS/RajaOngkir raw API data, and
 * lat/lng (kept for routing only, never printed on the customer-facing
 * receipt) — see the printing-feature audit for the business rule this
 * enforces (no financial ledger or internal routing data on a resi).
 */
class ShipmentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Shipment $this */
        $order = $this->order;
        $items = $this->orderItems;

        $mode = $this->shipped_at !== null ? 'post_pickup' : 'pre_pickup';

        $shippingMethodLabels = [
            'openroute' => 'Kurir Lokal',
            'rajaongkir' => 'Ekspedisi',
        ];

        // A RajaOngkir-based shipment's resi is Prime Classy's own internal
        // handover slip — never to be presented as an official JNE/J&T/
        // SiCepat/etc. carrier label, which this project never generates.
        $isOfficialCarrierLabel = false;

        $deliveryDate = $items->pluck('requested_delivery_date')->filter()->first()
            ?? $order->delivery_date_estimate;

        return [
            'shipment_id' => $this->id,
            'mode' => $mode,
            'order_no' => $order->order_no,
            'order_date' => $order->created_at,
            'recipient_name' => $order->recipient_name_snapshot,
            'recipient_phone' => $order->recipient_phone_snapshot,
            'address_line' => $order->address_snapshot,
            'village' => $order->village_snapshot,
            'district' => $order->district_snapshot,
            'regency' => $order->regency_snapshot,
            'province' => $order->province_snapshot,
            'delivery_date' => $deliveryDate,
            'shipping_method_code' => $this->shipping_provider_code,
            'shipping_method_label' => $shippingMethodLabels[$this->shipping_provider_code] ?? $this->shipping_provider_code,
            'is_official_carrier_label' => $isOfficialCarrierLabel,
            'courier_name' => $this->courier?->name,
            // Authoritative pickup timestamp — never "now()" at print time, so
            // a reprint always shows the original moment the courier actually
            // picked this shipment up (Shipment.shipped_at is set exactly
            // once, the first time this shipment reaches 'dikirim').
            'picked_up_at' => $this->shipped_at,
            'items' => $items->map(fn ($item) => [
                'sku' => $item->sku_snapshot,
                'product_name' => $item->product_name_snapshot,
                'variation_label' => $item->variation_label_snapshot,
                'quantity' => $item->fulfilled_quantity,
            ])->values(),
            'total_item_count' => $items->sum('fulfilled_quantity'),
            'notes' => $order->notes,
            // Minimal, non-financial payment indicator only (Blueprint print
            // requirement: no commission/fee ledger, no gateway internals).
            'payment' => [
                'is_cod' => $order->paymentMethod?->type === 'cod',
                // The authoritative amount a COD courier must collect. A
                // plain (non-DP) COD order never populates remaining_amount
                // (that field is DP-flow-specific — see OrderService::
                // createOrder), so "still owed" for COD is simply the
                // order's own total while payment_status hasn't reached
                // 'paid' — never recomputed here, just read straight off it.
                'cod_amount_due' => $order->paymentMethod?->type === 'cod' && $order->payment_status !== 'paid'
                    ? $order->total_amount
                    : null,
                'is_down_payment' => (float) $order->dp_amount > 0,
                'dp_paid_amount' => (float) $order->dp_amount > 0 ? $order->paid_amount : null,
                'dp_outstanding_amount' => (float) $order->dp_amount > 0 && $order->hasOutstanding()
                    ? $order->remaining_amount
                    : null,
                'is_fully_paid' => $order->isFullyPaid(),
            ],
        ];
    }
}
