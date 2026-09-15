<?php

namespace App\Http\Resources;

use App\Services\Payment\PaymentSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal_amount' => $this->subtotal_amount,
            'shipping_fee_amount' => $this->shipping_fee_amount,
            'admin_fee_amount' => $this->admin_fee_amount,
            'total_amount' => $this->total_amount,
            // DP / partial-payment accounting. Not sensitive — they are the
            // order's own amounts, and the konsumen needs them to know what is
            // still owed. remaining_amount > 0 always means NOT lunas.
            'dp_amount' => $this->dp_amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            // Canonical payment summary (PaymentSummaryService) — the single
            // formula every payment display (Order Detail, Transaction
            // Report, Google Sheets) reads instead of each re-deriving its
            // own. verified_dp is capped at requested_dp even once a
            // settlement pushes total_paid past it (the DP tranche's own
            // history never changes once verified).
            'payment_summary' => PaymentSummaryService::summarize($this->resource),
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
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'code' => $this->paymentMethod->code,
                'name' => $this->paymentMethod->name,
                'type' => $this->paymentMethod->type,
            ] : null),
            'payment_transaction' => $this->when(
                $this->relationLoaded('paymentTransactions') && $this->paymentTransactions->isNotEmpty(),
                // The most recent attempt — a DP order has more than one
                // (the DP itself, then the settlement), and the current one
                // is what the order-detail page acts on.
                fn () => new PaymentTransactionResource($this->paymentTransactions->sortByDesc('id')->first())
            ),
            'created_at' => $this->created_at,
        ];
    }
}
