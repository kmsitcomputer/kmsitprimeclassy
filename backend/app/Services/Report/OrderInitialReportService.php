<?php

namespace App\Services\Report;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan A — Transaksi Awal.
 *
 * One row per product item as it existed when the order was created. Every value
 * comes from the immutable snapshot written inside the checkout transaction
 * (`order_initial_report_items`), so later fulfilment, payment, catalogue or
 * master-data changes can never rewrite this report.
 *
 * Actor names are the exception: they are resolved live from the stable
 * hierarchy ids stored on the snapshot (agent_id / korsal_id / sales_id), never
 * from a name snapshot — see docs/BUSINESS-RULES.md ("actor names are the
 * exception: current/latest name from stable IDs").
 */
class OrderInitialReportService
{
    /** The locked 24-column contract of Laporan A (no "Posisi Uang" column). */
    public const COLUMNS = [
        'no' => 'No',
        'agn' => 'AGN',
        'invoice_no' => 'No Faktur',
        'order_date' => 'Tgl Order',
        'agent_name' => 'Agen',
        'korsal_name' => 'Korsal',
        'sales_name' => 'Sales',
        'sales_phone' => 'No. Telp Sales',
        'customer_name' => 'Konsumen',
        'customer_phone' => 'No. Telp Konsumen',
        'customer_address' => 'Alamat Konsumen',
        'sku' => 'Kode Kue',
        'product_name' => 'Jenis Kue',
        'category_name' => 'Kategori Kue',
        'unit_price' => 'Harga Kue',
        'quantity' => 'Qty',
        'line_total' => 'Total',
        'shipping_fee' => 'Ongkir',
        'discount' => 'Diskon',
        'invoice_total' => 'Total Pembayaran',
        'paid_amount' => 'Total Bayar',
        'remaining_amount' => 'Sisa',
        'payment_method' => 'Metode Pembayaran',
        'payment_status' => 'Status Pembayaran (Saat Transaksi)',
    ];

    /** Fields that must reach the sheet as numbers, not text. */
    public const NUMERIC_FIELDS = [
        'no', 'agn', 'unit_price', 'quantity', 'line_total',
        'shipping_fee', 'discount', 'invoice_total', 'paid_amount', 'remaining_amount',
    ];

    /**
     * @param  array{from?:?string,to?:?string}  $filters  UTC window (`to` exclusive), resolved by ReportPeriodFilter
     */
    public function query(?int $agentId = null, array $filters = []): Builder
    {
        $query = DB::table('order_initial_report_items as s')
            ->leftJoin('users as agent_users', 'agent_users.id', '=', 's.agent_id')
            ->leftJoin('users as korsal_users', 'korsal_users.id', '=', 's.korsal_id')
            ->leftJoin('users as sales_users', 'sales_users.id', '=', 's.sales_id')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY s.order_created_at DESC, s.order_no ASC, s.item_position ASC) as no')
            ->selectRaw('s.agent_id + 0 as agn')
            ->selectRaw('s.order_no as invoice_no')
            ->selectRaw("DATE_FORMAT(s.order_created_at, '%d/%m/%Y') as order_date")
            ->selectRaw("COALESCE(NULLIF(agent_users.name, ''), '-') as agent_name")
            ->selectRaw("COALESCE(NULLIF(korsal_users.name, ''), '-') as korsal_name")
            ->selectRaw("COALESCE(NULLIF(sales_users.name, ''), '-') as sales_name")
            ->selectRaw("COALESCE(NULLIF(sales_users.phone, ''), '-') as sales_phone")
            ->selectRaw("COALESCE(NULLIF(s.recipient_name_snapshot, ''), '-') as customer_name")
            ->selectRaw("COALESCE(NULLIF(s.recipient_phone_snapshot, ''), '-') as customer_phone")
            ->selectRaw("COALESCE(NULLIF(CONCAT_WS(', ', s.address_snapshot, s.village_snapshot, s.district_snapshot, s.regency_snapshot, s.province_snapshot), ''), '-') as customer_address")
            ->selectRaw("COALESCE(NULLIF(s.sku_snapshot, ''), '-') as sku")
            ->selectRaw("CASE WHEN s.variation_label_snapshot IS NULL OR s.variation_label_snapshot = '' THEN s.product_name_snapshot ELSE CONCAT(s.product_name_snapshot, ' - ', s.variation_label_snapshot) END as product_name")
            ->selectRaw("COALESCE(NULLIF(s.category_name_snapshot, ''), '-') as category_name")
            ->selectRaw('s.unit_price_snapshot + 0 as unit_price')
            ->selectRaw('s.initial_quantity + 0 as quantity')
            ->selectRaw('s.item_subtotal_snapshot + 0 as line_total')
            // Invoice-level values are only written on the first item row of the
            // invoice, so a spreadsheet SUM can never count them twice.
            ->selectRaw('CASE WHEN s.item_position = 1 THEN s.invoice_shipping_snapshot ELSE NULL END as shipping_fee')
            ->selectRaw('CASE WHEN s.item_position = 1 THEN s.invoice_discount_snapshot ELSE NULL END as discount')
            ->selectRaw('CASE WHEN s.item_position = 1 THEN s.invoice_total_snapshot ELSE NULL END as invoice_total')
            ->selectRaw('CASE WHEN s.item_position = 1 THEN s.initial_paid_snapshot ELSE NULL END as paid_amount')
            ->selectRaw('CASE WHEN s.item_position = 1 THEN s.initial_remaining_snapshot ELSE NULL END as remaining_amount')
            ->selectRaw("COALESCE(NULLIF(s.payment_method_name_snapshot, ''), NULLIF(s.payment_method_code_snapshot, ''), '-') as payment_method")
            ->selectRaw('s.initial_payment_status as payment_status');

        // Isolation is keyed on the agent recorded on the snapshot — the same
        // historical agent id the order was created with.
        if ($agentId !== null) {
            $query->where('s.agent_id', $agentId);
        }
        // Boundaries are already UTC instants (WIB calendar converted once by
        // ReportPeriodFilter); `to` is exclusive, so the last WIB day is whole.
        if (! empty($filters['from'])) {
            $query->where('s.order_created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('s.order_created_at', '<', $filters['to']);
        }

        return $query;
    }
}
