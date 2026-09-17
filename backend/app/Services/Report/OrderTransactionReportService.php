<?php

namespace App\Services\Report;

use App\Services\Payment\PaymentSummaryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Canonical item-level transaction dataset shared by dashboard, XLSX, and Google Sheets. */
class OrderTransactionReportService
{
    public const COLUMNS = [
        'order_no' => 'Order No',
        'order_date' => 'Tanggal',
        'sku' => 'SKU',
        'product' => 'Produk',
        'unit_price' => 'Harga',
        'quantity' => 'Qty',
        'item_status' => 'Status Item',
        'subtotal' => 'Subtotal',
        'customer' => 'Konsumen',
        'delivery_date' => 'Tgl Kirim',
        'courier' => 'Kurir',
        'order_status' => 'Status Order',
        'sales' => 'Sales',
        'korsal' => 'Korsal',
        // Order-level payment facts (PaymentSummaryService), repeated on
        // every item row of this same order — fine for this item-level
        // detail/export context (Blueprint §W), but NEVER sum these across
        // rows of a multi-item order; that double-counts. Use the
        // order-level finance summary for aggregates instead.
        'payment_method' => 'Metode Bayar',
        'grand_total' => 'Grand Total',
        'dp_paid' => 'DP Dibayar',
        'total_paid' => 'Total Dibayar',
        'remaining_balance' => 'Sisa Pembayaran',
        'payment_status' => 'Status Pembayaran',
        // additional_payment_status is THIS item's own additional payment
        // (OrderItem.additional_payment_id — increaseFulfillment always
        // targets one item at a time, so the FK is precise, never ambiguous).
        // refund_status is collapsed across this item's OrderItemAdjustment
        // row(s) via the same "any pending? -> pending, else latest" rule as
        // PaymentSummaryService, since one item can accumulate more than one
        // adjustment over time and this report stays one-row-per-item.
        'additional_payment_status' => 'Status Additional Payment',
        'refund_status' => 'Status Refund',
    ];

    public function query(?int $agentId = null, ?int $korsalScopeId = null, array $filters = []): Builder
    {
        $salesReferrerId = "CASE WHEN customer_roles.slug IN ('agen','korsal','sales') THEN customers.id ELSE COALESCE(o.sales_id, o.korsal_id, o.agent_id) END";
        $salesReferrerName = "CASE WHEN customer_roles.slug IN ('agen','korsal','sales') THEN customers.name ELSE COALESCE(sales_users.name, korsal_users.name, agent_users.name) END";
        $korsalName = "CASE WHEN customer_roles.slug = 'agen' THEN NULL WHEN customer_roles.slug = 'korsal' THEN customers.name ELSE korsal_users.name END";

        $query = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 'i.shipment_id')
            ->leftJoin('couriers as c', 'c.id', '=', 'sh.courier_id')
            ->leftJoin('users as customers', 'customers.id', '=', 'o.konsumen_id')
            ->leftJoin('roles as customer_roles', 'customer_roles.id', '=', 'customers.role_id')
            ->leftJoin('users as sales_users', 'sales_users.id', '=', 'o.sales_id')
            ->leftJoin('users as korsal_users', 'korsal_users.id', '=', 'o.korsal_id')
            ->leftJoin('users as agent_users', 'agent_users.id', '=', 'o.agent_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'o.payment_method_id')
            ->leftJoin('order_additional_payments as ap', 'ap.id', '=', 'i.additional_payment_id')
            ->select([
                'ap.status as additional_payment_status',
                'o.id as order_id', 'i.id as order_item_id', 'o.agent_id',
                'c.id as courier_id', 'o.sales_id', 'o.korsal_id',
                'o.order_no',
                'i.status as item_status', 'o.status as order_status', 'o.payment_status',
            ])
            ->selectRaw("DATE_FORMAT(o.created_at, '%d/%m/%Y') as order_date")
            ->selectRaw("COALESCE(NULLIF(i.sku_snapshot, ''), '-') as sku")
            ->selectRaw("CASE WHEN i.variation_label_snapshot IS NULL OR i.variation_label_snapshot = '' THEN i.product_name_snapshot ELSE CONCAT(i.product_name_snapshot, ' - ', i.variation_label_snapshot) END as product")
            ->selectRaw('i.unit_price_snapshot + 0 as unit_price')
            ->selectRaw('i.fulfilled_quantity + 0 as quantity')
            ->selectRaw('i.subtotal_snapshot + 0 as subtotal')
            ->selectRaw("COALESCE(NULLIF(o.recipient_name_snapshot, ''), customers.name, '-') as customer")
            ->selectRaw("COALESCE(DATE_FORMAT(i.requested_delivery_date, '%d/%m/%Y'), '-') as delivery_date")
            ->selectRaw("COALESCE(NULLIF(c.name, ''), '-') as courier")
            ->selectRaw("COALESCE(($salesReferrerName), '-') as sales")
            ->selectRaw("COALESCE(($korsalName), '-') as korsal")
            ->selectRaw("$salesReferrerId as sales_referrer_id")
            ->selectRaw("COALESCE(NULLIF(pm.name, ''), '-') as payment_method")
            ->selectRaw('o.total_amount + 0 as grand_total')
            ->selectRaw(PaymentSummaryService::verifiedDpSql('o.dp_amount', 'o.paid_amount').' as dp_paid')
            ->selectRaw('o.paid_amount + 0 as total_paid')
            ->selectRaw('o.remaining_amount + 0 as remaining_balance')
            ->selectRaw("(
                SELECT COALESCE(
                    MAX(CASE WHEN oia.refund_status = 'pending' THEN 'pending' END),
                    (SELECT oia2.refund_status FROM order_item_adjustments oia2 WHERE oia2.order_item_id = i.id ORDER BY oia2.id DESC LIMIT 1)
                )
                FROM order_item_adjustments oia WHERE oia.order_item_id = i.id
            ) as refund_status");

        if ($agentId !== null) {
            $query->where('o.agent_id', $agentId);
        }
        if ($korsalScopeId !== null) {
            $query->where('o.korsal_id', $korsalScopeId);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('o.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('o.created_at', '<=', $filters['to']);
        }
        if (! empty($filters['delivery_date_from'])) {
            $query->whereDate('i.requested_delivery_date', '>=', $filters['delivery_date_from']);
        }
        if (! empty($filters['delivery_date_to'])) {
            $query->whereDate('i.requested_delivery_date', '<=', $filters['delivery_date_to']);
        }
        if (! empty($filters['sales_id'])) {
            $query->whereRaw("$salesReferrerId = ?", [(int) $filters['sales_id']]);
        }
        if (! empty($filters['korsal_id'])) {
            $query->where('o.korsal_id', $filters['korsal_id']);
        }
        if (! empty($filters['courier_id'])) {
            $query->where('c.id', $filters['courier_id']);
        }
        if (! empty($filters['item_status'] ?? $filters['status'] ?? null)) {
            $query->where('i.status', $filters['item_status'] ?? $filters['status']);
        }
        if (! empty($filters['order_status'])) {
            $query->where('o.status', $filters['order_status']);
        }

        return $query->orderByDesc('o.created_at')->orderBy('o.order_no')->orderBy('i.id');
    }
}
