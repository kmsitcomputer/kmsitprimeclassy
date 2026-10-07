<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The kurir-facing order view — deliberately excludes every money field
 * (subtotal/total/payment_status/payment_method/fees) per Blueprint: "Kurir
 * tidak boleh melakukan transaksi, mengubah harga, mengubah fee, mengubah
 * payment." Only what's needed to actually execute the delivery.
 */
class CourierOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $viewerIsKurir = $actor?->isRole('kurir') ?? false;
        $viewerIsSubActor = $actor?->isRole('sales-kurir-sub') ?? false;
        $viewerIsKoordinator = $actor?->isRole('koordinator-kurir') ?? false;
        $viewerCourierId = $actor?->courierProfile?->id;
        $viewerUserId = $actor?->id;

        // R-04 / §C: a normal Kurir's queue may show work they have not claimed yet. Minimize the
        // pre-claim projection (no recipient contact / street address / coordinates) and expose the
        // full operational detail only once the shipment is assigned to THEM. A Sales-Kurir-Sub's
        // own self_sub shipments are already theirs, so they always get full detail.
        // A1-01: a Koordinator-Kurir self-executor behaves exactly like a normal Kurir; the same
        // minimal-until-assigned projection applies, driven by their own Courier profile.
        $assignedToViewer = false;
        if ($viewerIsKurir || $viewerIsKoordinator) {
            $assignedToViewer = $this->relationLoaded('items') && $this->items->contains(
                fn ($item) => $item->shipment
                    && $item->shipment->courier_id !== null
                    && $item->shipment->courier_id === $viewerCourierId
            );
        }
        $minimal = ($viewerIsKurir || $viewerIsKoordinator) && ! $assignedToViewer;

        $row = [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'detail_available' => ! $minimal,
            'village' => $this->village_snapshot,
            'district' => $this->district_snapshot,
            'regency' => $this->regency_snapshot,
            'province' => $this->province_snapshot,
            // The order-level query only requires ONE item to be eligible (still
            // 'diproses', or 'dikirim'/beyond on THIS kurir's own shipment) for the
            // whole order to appear — an order can otherwise mix several products
            // each on a different shipment/courier (Blueprint: "satu order bisa
            // beberapa kurir"). Without this per-item filter, a sibling item
            // already picked up by a DIFFERENT kurir would leak into this kurir's
            // view just because some other item in the same order kept it
            // matching — filtered out here so 'dikirim'/'terkirim' items only ever
            // show to the kurir actually holding that shipment.
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn ($item) => $this->itemVisibleToViewer($item, $viewerIsKurir || $viewerIsKoordinator, $viewerIsSubActor, $viewerCourierId, $viewerUserId, $viewerIsKoordinator))
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'shipment_id' => $item->shipment_id,
                    'product_name' => $item->product_name_snapshot,
                    'variation_label' => $item->variation_label_snapshot,
                    // Historical transactions keep the SKU captured at order time
                    // (never the live catalog SKU); '-' in the UI when absent.
                    'sku' => $item->sku_snapshot,
                    'quantity' => $item->fulfilled_quantity,
                    'status' => $item->status,
                    'requested_delivery_date' => $item->requested_delivery_date,
                ])),
            'created_at' => $this->created_at,
        ];

        if (! $minimal) {
            $row += [
                'recipient_name' => $this->recipient_name_snapshot,
                'recipient_phone' => $this->recipient_phone_snapshot,
                'address' => $this->address_snapshot,
                'latitude' => $this->latitude_snapshot,
                'longitude' => $this->longitude_snapshot,
            ];
        }

        return $row;
    }

    private function itemVisibleToViewer($item, bool $viewerIsKurir, bool $viewerIsSubActor, ?int $viewerCourierId, ?int $viewerUserId, bool $viewerIsKoordinator = false): bool
    {
        // R-03: a Sales-Kurir-Sub only ever sees items on their OWN self_sub shipment.
        if ($viewerIsSubActor) {
            return $item->shipment?->self_delivered_by_user_id !== null
                && (int) $item->shipment->self_delivered_by_user_id === (int) $viewerUserId;
        }

        if ($viewerIsKoordinator) {
            return $item->shipment?->delivery_mode === 'standard'
                && $viewerCourierId !== null
                && $item->shipment?->courier_id === $viewerCourierId;
        }

        // A1-01: a koordinator-kurir self-executor is treated exactly like a normal kurir with a
        // Courier profile — items on their own assigned shipment, and 'diproses' items (their
        // dispatch queue remains visible pre-claim like any kurir's).
        if (! $viewerIsKurir) {
            return true;
        }

        // A self_sub item belongs to its owning Sales-Kurir-Sub, never a normal Kurir's queue.
        if ($item->shipment?->delivery_mode === 'self_sub') {
            return false;
        }

        if (in_array($item->status, ['diterima', 'diproses'], true)) {
            return true;
        }

        $shipmentCourierId = $item->shipment?->courier_id;

        return $shipmentCourierId === null || $shipmentCourierId === $viewerCourierId;
    }
}
