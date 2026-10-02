<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Payment\PaymentSummaryService;

/**
 * Thermal shipping-receipt payload — read-only, printable before and after
 * courier pickup. "Pickup" has no dedicated timestamp column in this
 * project; it is the same event as the diproses->dikirim transition, whose
 * authoritative timestamp is Shipment.shipped_at (see CourierService::
 * syncShipmentProgress). mode is therefore derived, never accepted from the
 * caller: 'post_pickup' once shipped_at is set, 'pre_pickup' until then.
 *
 * Deliberately excludes: commission amounts (agent/sales/korsal/courier fee),
 * Order-wide payment balances on multi-date receipts, payment gateway/bank
 * internals, ORS/RajaOngkir raw API data, and lat/lng. The canonical shipment
 * fee snapshot and earliest-group initial DP are the only group-scoped amounts.
 */
class ShipmentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Shipment $this */
        $order = $this->order;
        // One resi per Order + delivery date: every ACTIVE line of this shipment's group (cancelled/zero lines are not printed).
        $items = $this->orderItems->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)->values();

        $mode = $this->shipped_at !== null ? 'post_pickup' : 'pre_pickup';

        $meta = is_array($this->provider_meta) ? $this->provider_meta : [];
        $shippingMethodLabel = match ($this->shipping_provider_code) {
            'openroute' => 'Kurir Online',
            'free' => 'Gratis',
            'pickup' => 'Pickup',
            'rajaongkir' => trim(strtoupper((string) ($meta['courier'] ?? '')).' '.strtoupper((string) ($meta['service'] ?? ''))) ?: 'Ekspedisi',
            default => $this->shipping_provider_code,
        };

        // A RajaOngkir-based shipment's resi is Prime Classy's own internal
        // handover slip — never to be presented as an official JNE/J&T/
        // SiCepat/etc. carrier label, which this project never generates.
        $isOfficialCarrierLabel = false;

        $deliveryDate = $items->map(fn ($item) => $item->requested_delivery_date?->toDateString() ?? $order->delivery_date_estimate?->toDateString())->filter()->first();
        $activeOrderItems = $order->items->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0);
        $itemDeliveryDate = fn ($item) => $item->requested_delivery_date?->toDateString() ?? $order->delivery_date_estimate?->toDateString() ?? '';
            $activeDates = $activeOrderItems->map($itemDeliveryDate)->unique();
            $earliestDate = $activeDates->filter()->sort()->first();
            $activeDateCount = $activeDates->count();
        // Historical committed shipments can leave more than one receipt inside one date group. Give the
        // date group's one-time DP credit to exactly one deterministic receipt (the lowest shipment id).
        $earliestShipmentId = $earliestDate === null ? null : $activeOrderItems
            ->filter(fn ($item) => $itemDeliveryDate($item) === $earliestDate)
            ->pluck('shipment_id')->filter()->min();
        $isEarliestGroupReceipt = $earliestDate !== null
            && $deliveryDate === $earliestDate
            && (int) $this->id === (int) $earliestShipmentId;
        $verifiedDp = (float) PaymentSummaryService::summarize($order)['verified_dp'];

        return [
            'shipment_id' => $this->id,
            'mode' => $mode,
            'shipment_status' => $this->status,
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
            'shipping_fee_amount' => $this->shipping_fee_snapshot,
            'shipping_method_code' => $this->shipping_provider_code,
            'shipping_method_label' => $shippingMethodLabel,
            'is_official_carrier_label' => $isOfficialCarrierLabel,
            'tracking_number' => $this->tracking_number,
            'courier_name' => $this->courier?->name ?? ($this->isSelfDelivery() ? $this->selfDeliveredBy?->name : null),
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
            // A resi never presents an Order-wide balance as a date-group balance. The only numeric
            // payment fact permitted here is the initial verified DP, and only on the earliest group.
            'payment' => [
                'is_cod' => $order->paymentMethod?->type === 'cod',
                'cod_amount_due' => $order->paymentMethod?->type === 'cod' && $activeDateCount === 1 && $order->payment_status !== 'paid'
                    ? $order->total_amount : null,
                'is_down_payment' => (float) $order->dp_amount > 0,
                'is_earliest_delivery_group' => $isEarliestGroupReceipt,
                'initial_dp_amount' => $isEarliestGroupReceipt && (float) $order->dp_amount > 0
                    ? $order->dp_amount : null,
                'initial_dp_credit' => $isEarliestGroupReceipt && $verifiedDp > 0 ? $verifiedDp : null,
                'order_has_multiple_delivery_groups' => $activeDateCount > 1,
            ],
        ];
    }
}
