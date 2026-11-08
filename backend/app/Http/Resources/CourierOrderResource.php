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

        // R-04 / §C: operational detail is exposed only for work assigned to THEM (the queue
        // itself is assignment-only since 2026-11-08; the minimal projection stays as defence in
        // depth). A Sales-Kurir-Sub's
        // own self_sub shipments are already theirs, so they always get full detail.
        // A1-01: a Koordinator-Kurir self-executor behaves exactly like a normal Kurir; the same
        // minimal-until-assigned projection applies, driven by their own Courier profile.
        // CR-SALES-KURIR-SUB-DELIVERY-001: a Sales-Kurir-Sub assigned as the executor of a
        // standard shipment sees exactly that shipment's items (same recorded-executor rule as
        // a Koordinator-Kurir) — plus their own self_sub items. Anything else stays hidden.
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
        // R-03: a Sales-Kurir-Sub sees items on their OWN self_sub shipment — plus, since
        // CR-SALES-KURIR-SUB-DELIVERY-001, items on a standard shipment explicitly assigned
        // to their own Courier profile. Never an unassigned shipment, never another
        // courier's, never another Sales-Kurir-Sub's self_sub.
        if ($viewerIsSubActor) {
            if ($item->shipment?->self_delivered_by_user_id !== null
                && (int) $item->shipment->self_delivered_by_user_id === (int) $viewerUserId
                && $item->shipment->isSelfPickupAuthorizedFor(request()->user())) {
                return true;
            }

            return $item->shipment?->delivery_mode === 'standard'
                && $viewerCourierId !== null
                && $item->shipment?->courier_id === $viewerCourierId;
        }

        if ($viewerIsKoordinator) {
            return $item->shipment?->delivery_mode === 'standard'
                && $viewerCourierId !== null
                && $item->shipment?->courier_id === $viewerCourierId;
        }

        if (! $viewerIsKurir) {
            return true;
        }

        // Assignment-only (Owner decision 2026-11-08): a kurir sees only items on a standard
        // shipment recorded to their own Courier profile.
        return $item->shipment?->delivery_mode === 'standard'
            && $viewerCourierId !== null
            && $item->shipment?->courier_id === $viewerCourierId;
    }
}
