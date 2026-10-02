<?php

namespace App\Http\Resources;

use App\Services\Payment\PaymentSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        // R-04 / §D: operational Order access does NOT imply the financial projection. Gudang and
        // Kurir get the operational order view only (no DP/paid/remaining/payment summary/ledger);
        // financial truth stays canonical in the payment layer for the authorized roles.
        $seesFinancials = $user !== null
            && $user->isRole('super_admin', 'agen', 'admin', 'keuangan', 'konsumen', 'sales', 'sales-kurir-sub', 'korsal');

        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'payment_status' => $this->when($seesFinancials, $this->payment_status),
            'subtotal_amount' => $this->when($seesFinancials, $this->subtotal_amount),
            'shipping_fee_amount' => $this->when($seesFinancials, $this->shipping_fee_amount),
            'admin_fee_amount' => $this->when($seesFinancials, $this->admin_fee_amount),
            'total_amount' => $this->when($seesFinancials, $this->total_amount),
            // DP / partial-payment accounting. Not sensitive to the financial roles — they are the
            // order's own amounts, and the konsumen needs them to know what is still owed.
            // remaining_amount > 0 always means NOT lunas.
            'dp_amount' => $this->when($seesFinancials, $this->dp_amount),
            'paid_amount' => $this->when($seesFinancials, $this->paid_amount),
            'remaining_amount' => $this->when($seesFinancials, $this->remaining_amount),
            // Canonical payment summary (PaymentSummaryService) — the single formula every payment
            // display reads instead of each re-deriving its own.
            'payment_summary' => $this->when($seesFinancials, fn () => PaymentSummaryService::summarize($this->resource)),
            'recipient_name' => $this->recipient_name_snapshot,
            'recipient_phone' => $this->recipient_phone_snapshot,
            'address' => $this->address_snapshot,
            // The exact pin the konsumen picked at checkout — only meaningful
            // to show as a map when shipping_provider is 'openroute' ("Kurir
            // Online"; see ShippingQuoteService's own $labels mapping). Not
            // sensitive (it's the order's own delivery address, chosen by
            // its own konsumen), so exposed unconditionally; the frontend
            // decides which roles render the clickable-Google-Maps widget.
            'latitude' => $this->latitude_snapshot,
            'longitude' => $this->longitude_snapshot,
            'shipping_provider' => $this->whenLoaded('shipments', fn () => $this->shipments->first()?->shipping_provider_code),
            'delivery_date_estimate' => $this->delivery_date_estimate,
            'cancellation_reason' => $this->cancellation_reason,
            'konsumen' => $this->whenLoaded('konsumen', fn () => $this->konsumen ? [
                'name' => $this->konsumen->name,
                'phone' => $this->konsumen->phone,
            ] : null),
            // Who made this sale/manages this branch — needed by korsal's own
            // order view ("identitas siapa sales dan konsumennya"), and by
            // office/agen roles. Never the konsumen's own view of their own
            // order — that's internal referral-chain identity, not something
            // a customer needs or should see on their own order detail.
            'sales' => $this->when(
                $request->user()?->isRole('konsumen') !== true,
                fn () => $this->whenLoaded('sales', fn () => $this->sales ? [
                    'id' => $this->sales->id,
                    'name' => $this->sales->name,
                ] : null)
            ),
            'korsal' => $this->when(
                $request->user()?->isRole('konsumen') !== true,
                fn () => $this->whenLoaded('korsal', fn () => $this->korsal ? [
                    'id' => $this->korsal->id,
                    'name' => $this->korsal->name,
                ] : null)
            ),
            // Status pengembalian milik konsumen sendiri — refund_amount di
            // sini adalah uang yang KEMBALI ke konsumen, bukan cost/fee
            // internal, jadi aman ditampilkan ke konsumen pemilik order ini.
            'returns' => $this->whenLoaded('returnRequests', fn () => $this->returnRequests->map(fn ($r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'status' => $r->status,
                'reviewed_at' => $r->reviewed_at,
                'total_refund_amount' => $r->total_refund_amount,
                'items' => $r->items->map(fn ($i) => [
                    'order_item_id' => $i->order_item_id,
                    'quantity_returned' => $i->quantity_returned,
                    'status' => $i->status,
                    'refund_status' => $i->refund_status,
                ]),
            ])),
            // One entry per DISTINCT courier actually assigned across this
            // order's shipments — never a placeholder/null/empty-object
            // block, and never more than one row per courier even if they
            // hold several of this order's shipments. See OrderItemResource
            // for which specific item each courier is carrying.
            'couriers' => $this->when(
                $this->relationLoaded('shipments'),
                fn () => $this->shipments
                    ->pluck('courier')
                    ->filter()
                    ->unique('id')
                    ->values()
                    ->map(fn ($courier) => ['name' => $courier->name, 'phone' => $courier->user?->phone])
            ),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            // R-03: derived delivery groups — one per (order, requested_delivery_date). There is NO
            // invoice/delivery-group table: grouping is a pure projection over order items, and the
            // Order-level payment truth (payment_summary above) is never duplicated per group.
            // Production UAT: the group is also the CONSUMER-facing delivery plan, so it carries the group's
            // active products/quantities, a derived delivery status and safe shipment/resi info. It carries NO
            // Stock Request / Gudang proposal / reservation / financial data. Shipments are one per
            // (order, date) for mutable groups; committed ones may differ, so the list is per distinct shipment.
            'delivery_groups' => $this->whenLoaded('items', function () {
                $shipments = $this->relationLoaded('shipments') ? $this->shipments->keyBy('id') : collect();
                $rank = ['diterima' => 0, 'diproses' => 1, 'dikirim' => 2, 'terkirim' => 3, 'pengembalian' => 4, 'kembali' => 5];

                return $this->items
                    ->groupBy(fn ($item) => $item->requested_delivery_date?->toDateString() ?? '')
                    ->map(function ($items, $date) use ($shipments, $rank) {
                        $active = $items->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)->values();
                        $statuses = $active->pluck('status')->unique();
                        $groupShipments = $active->map(fn ($item) => $shipments->get($item->shipment_id) ?? $item->shipment)
                            ->filter()->unique('id')->values();
                        $deliveryMethods = $groupShipments->map(function ($shipment) {
                            $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];

                            return match ($shipment->shipping_provider_code) {
                                'openroute' => 'Kurir Online',
                                'free' => 'Gratis',
                                'pickup' => 'Pickup',
                                'rajaongkir' => trim(strtoupper((string) ($meta['courier'] ?? '')).' '.strtoupper((string) ($meta['service'] ?? ''))) ?: 'Ekspedisi',
                                default => $shipment->shipping_provider_code,
                            };
                        })->filter()->unique()->values();
                        $courierNames = $groupShipments->map(function ($shipment) {
                            if ($shipment->shipping_provider_code !== 'openroute') {
                                return null;
                            }

                            return $shipment->courier?->name
                                ?? ($shipment->isSelfDelivery() ? $shipment->selfDeliveredBy?->name : null);
                        })->filter()->unique()->values();

                        return [
                            'delivery_date' => $date !== '' ? $date : null,
                            'item_ids' => $items->pluck('id')->values(),
                            'item_count' => $items->count(),
                            'total_quantity' => (int) $items->sum('fulfilled_quantity'),
                            'shipment_ids' => $items->pluck('shipment_id')->filter()->unique()->values(),
                            'status' => $active->isEmpty()
                                ? 'dibatalkan'
                                : $statuses->sortBy(fn ($st) => $rank[$st] ?? 99)->first(),
                            'delivery_methods' => $deliveryMethods,
                            'courier_names' => $courierNames,
                            'items' => $active->map(fn ($item) => [
                                'id' => $item->id,
                                'product_name' => $item->product_name_snapshot,
                                'variation_label' => $item->variation_label_snapshot,
                                'sku' => $item->sku_snapshot,
                                'quantity' => (int) $item->fulfilled_quantity,
                                'status' => $item->status,
                            ])->values(),
                            'shipments' => $active->pluck('shipment_id')->filter()->unique()->values()->map(function ($id) use ($shipments, $active) {
                                $shipment = $shipments->get($id) ?? $active->firstWhere('shipment_id', $id)?->shipment;

                                return $shipment ? [
                                    'id' => $shipment->id,
                                    'status' => $shipment->status,
                                    'tracking_number' => $shipment->tracking_number,
                                    'shipped_at' => $shipment->shipped_at,
                                    'delivered_at' => $shipment->delivered_at,
                                    'shipping_provider_code' => $shipment->shipping_provider_code,
                                    'shipping_method_label' => $this->deliveryMethodLabel($shipment),
                                    'courier_name' => $shipment->shipping_provider_code === 'openroute' ? $shipment->courier?->name : null,
                                ] : ['id' => $id];
                            })->values(),
                        ];
                    })
                    ->values();
            }),
            // R-03: append-only Admin delivery-verification history — Admin/Super Admin only.
            // DeliveryVerificationController enforces the same boundary; exposing it through the
            // generic Order detail to Konsumen/Sales/Korsal/Kurir would bypass it (verifier
            // identity + internal notes). Non-Admin UIs use GET /shipments/{shipment}/delivery-verifications.
            'delivery_verifications' => $this->when(
                $this->relationLoaded('deliveryVerifications')
                    && ($request->user()?->isRole('super_admin', 'admin') ?? false),
                fn () => DeliveryVerificationResource::collection($this->deliveryVerifications)->resolve()
            ),
            'payment_method' => $this->when(
                $seesFinancials && $this->relationLoaded('paymentMethod'),
                fn () => $this->paymentMethod ? [
                    'code' => $this->paymentMethod->code,
                    'name' => $this->paymentMethod->name,
                    'type' => $this->paymentMethod->type,
                ] : null
            ),
            'payment_transaction' => $this->when(
                $seesFinancials && $this->relationLoaded('paymentTransactions') && $this->paymentTransactions->isNotEmpty(),
                // The most recent attempt — a DP order has more than one
                // (the DP itself, then the settlement), and the current one
                // is what the order-detail page acts on.
                fn () => new PaymentTransactionResource($this->paymentTransactions->sortByDesc('id')->first())
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function deliveryMethodLabel($shipment): ?string
    {
        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];

        return match ($shipment->shipping_provider_code) {
            'openroute' => 'Kurir Online',
            'free' => 'Gratis',
            'pickup' => 'Pickup',
            'rajaongkir' => trim(strtoupper((string) ($meta['courier'] ?? '')).' '.strtoupper((string) ($meta['service'] ?? ''))) ?: 'Ekspedisi',
            default => $shipment->shipping_provider_code,
        };
    }
}
