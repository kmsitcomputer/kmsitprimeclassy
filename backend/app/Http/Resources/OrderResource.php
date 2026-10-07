<?php

namespace App\Http\Resources;

use App\Services\Payment\PaymentSummaryService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Mirrors InvoiceController::show's own gate exactly — the order's own
     * konsumen + same-branch financial roles. Centralizing it here keeps the
     * UI capability and the backend denial from ever drifting apart.
     */
    private function viewerCanDownloadInvoice(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! method_exists($user, 'isRole')) {
            return false;
        }

        /** @var \App\Models\User $user */
        if ($user->isRole('super_admin', 'agen', 'admin', 'keuangan')) {
            // BelongsToAgentScope already confines these to their branch.
            return true;
        }

        return $user->isRole('konsumen') && (int) $this->konsumen_id === (int) $user->id;
    }

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
            // A1-21: promotion attribution is part of the financial projection —
            // a discounted total cannot be reconciled without it.
            'discount_amount' => $this->when($seesFinancials, $this->discount_amount),
            'effective_discount_amount' => $this->when($seesFinancials, fn () => number_format($this->resource->effectiveDiscountAmount(), 2, '.', '')),
            'voucher_id' => $this->when($seesFinancials, $this->voucher_id),
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
            // A1-15: authorized owner/capability contract for the invoice PDF —
            // the consumer UI previously required order.konsumen_id (never
            // serialized), permanently hiding the button. Server derives the
            // capability: the order's own konsumen + same-branch financial
            // roles. No ownership id is broadcast — matching the Invoice
            // controller's own gate exactly (viewer::kapabilitas).
            'viewer_can_download_invoice' => $this->when($request->user() !== null, fn () => $this->viewerCanDownloadInvoice($request->user())),
            'recipient_name' => $this->recipient_name_snapshot,
            'recipient_phone' => $this->recipient_phone_snapshot,
            'address' => $this->address_snapshot,
            // A1-21: the persisted structured-address ids + postal code. These
            // are canonical region identifiers (never money/identity), so they
            // are shown to every authorized order viewer — the same set the
            // checkout persisted and dispatch/fulfillment filter by.
            'province_id' => $this->province_id,
            'regency_id' => $this->regency_id,
            'district_id' => $this->district_id,
            'village_id' => $this->village_id,
            'postal_code' => $this->postal_code,
            // The exact pin the konsumen picked at checkout — only meaningful
            // to show as a map when shipping_provider is 'openroute' ("Kurir
            // Online"; see ShippingQuoteService's own $labels mapping). Not
            // sensitive (it's the order's own delivery address, chosen by
            // its own konsumen), so exposed unconditionally; the frontend
            // decides which roles render the clickable-Google-Maps widget.
            'latitude' => $this->latitude_snapshot,
            'longitude' => $this->longitude_snapshot,
            'shipping_provider' => $this->whenLoaded('shipments', fn () => $this->shipments->first()?->shipping_provider_code),
            // UAT-005 LOCKED delivery-date rule (Human 2026-10-07): whether an item's requested
            // delivery date may be changed at all. Derived server-side from the canonical
            // shipment fields via Shipment::isDeliveryDateReschedulable() — the UI gate mirrors
            // this instead of re-deriving provider/mode logic (which previously let a self_delivery
            // order hide a control the backend actually allows). 'openroute' (Kurir Online) and
            // 'self_sub' (Self Delivery/Sub) may reschedule; 'rajaongkir' and 'pickup' may not.
            // It says nothing about WHO may act — that stays the authorization layer's job.
            'reschedule_allowed' => $this->whenLoaded('shipments', fn () => $this->shipments->contains(
                fn ($shipment) => $shipment->isDeliveryDateReschedulable()
            )),
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
            // A1-14: the financial AMOUNT is gated to the canonical financial
            // audience like every other money field — an operational viewer
            // (koordinator-kurir/gudang) still sees the return's operational
            // status/quantity, but never total_refund_amount. Built as an
            // explicit conditional array: JsonResource::when() markers are not
            // resolved inside Collection::map() closures, so a plain merge is
            // the reliable projection boundary here.
            'returns' => $this->whenLoaded('returnRequests', fn () => $this->returnRequests->map(fn ($r) => array_merge(
                [
                    'id' => $r->id,
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'reviewed_at' => $r->reviewed_at,
                    'items' => $r->items->map(fn ($i) => [
                        'order_item_id' => $i->order_item_id,
                        'quantity_returned' => $i->quantity_returned,
                        'status' => $i->status,
                        'refund_status' => $i->refund_status,
                    ]),
                ],
                $seesFinancials ? ['total_refund_amount' => $r->total_refund_amount] : []
            ))),
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
            // UAT-005 LOCKED: Shipment is the DELIVERY-DATE unit, so the UI needs each shipment's
            // canonical delivery date to label its card / Print Resi row. Derived server-side from the
            // shipment's OWN items (never from arbitrary first-item UI state), and only when every item
            // on that shipment agrees — a mixed-date shipment reports null rather than inventing a date.
            'shipment_delivery_dates' => $this->when(
                $this->relationLoaded('shipments') && $this->relationLoaded('items'),
                fn () => $this->shipments->mapWithKeys(function ($shipment) {
                    $dates = $this->items
                        ->where('shipment_id', $shipment->id)
                        ->pluck('requested_delivery_date')
                        ->filter()
                        ->map(fn ($d) => $d instanceof \Carbon\CarbonInterface ? $d->toDateString() : (string) $d)
                        ->unique();

                    return [$shipment->id => [
                        // A single canonical date for the unit; null when unset or mixed.
                        'delivery_date' => $dates->count() === 1 ? $dates->first() : null,
                        'status' => $shipment->status,
                        'delivery_mode' => $shipment->delivery_mode,
                    ]];
                })
            ),
            // R-03: derived delivery groups — one per (order, requested_delivery_date). There is NO
            // invoice/delivery-group table: grouping is a pure projection over order items, and the
            // Order-level payment truth (payment_summary above) is never duplicated per group.
            'delivery_groups' => $this->whenLoaded('items', fn () => $this->items
                ->groupBy(fn ($item) => $item->requested_delivery_date?->toDateString() ?? '')
                ->map(fn ($items, $date) => [
                    'delivery_date' => $date !== '' ? $date : null,
                    'item_ids' => $items->pluck('id')->values(),
                    'item_count' => $items->count(),
                    'total_quantity' => (int) $items->sum('fulfilled_quantity'),
                    'shipment_ids' => $items->pluck('shipment_id')->filter()->unique()->values(),
                ])
                ->values()),
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
}
