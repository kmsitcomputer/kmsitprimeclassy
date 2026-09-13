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
        $viewerCourierId = $actor?->courierProfile?->id;

        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'recipient_name' => $this->recipient_name_snapshot,
            'recipient_phone' => $this->recipient_phone_snapshot,
            'address' => $this->address_snapshot,
            'village' => $this->village_snapshot,
            'district' => $this->district_snapshot,
            'regency' => $this->regency_snapshot,
            'province' => $this->province_snapshot,
            'latitude' => $this->latitude_snapshot,
            'longitude' => $this->longitude_snapshot,
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
                ->filter(fn ($item) => $this->itemVisibleToViewer($item, $viewerIsKurir, $viewerCourierId))
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
    }

    private function itemVisibleToViewer($item, bool $viewerIsKurir, ?int $viewerCourierId): bool
    {
        if (! $viewerIsKurir) {
            return true;
        }

        if (in_array($item->status, ['diterima', 'diproses'], true)) {
            return true;
        }

        $shipmentCourierId = $item->shipment?->courier_id;

        return $shipmentCourierId === null || $shipmentCourierId === $viewerCourierId;
    }
}
