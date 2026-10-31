<?php

namespace App\Services\Report;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan C — Staf Worksheet (SimpleV2 47 columns plus AV Status Pesanan).
 *
 * Mirrors the SimpleV2 Google-Sheets payload geometry (verified against
 * simpelv2.git HEAD: TransactionExport.php:117-161 / TransactionService.php:937-985):
 * one row per order ITEM, invoice-level money values repeated only on the
 * invoice's first item row (PrimeClassy anti-double-count convention, same as
 * Laporan A — SV2 repeated them on every row and inflated column SUMs; this is
 * a deliberate, documented improvement).
 *
 * MANUAL columns are now ONLY the three below. `money_position` (posisi uang
 * SAAT TRANSAKSI) is DERIVED from the payment method (COD -> konsumen,
 * bank transfer/DP -> keuangan); `update_payment_status` is SYSTEM = the
 * latest canonical order payment status. `update_money_position` is the
 * authoritatively MANUAL CURRENT-custody column (konsumen|keuangan|sales|
 * korsal, enforced as a real Sheets dropdown) and is never rewritten by sync.
 * DP / Cicilan / Pelunasan (+dates) come from verified payment_transactions
 * rows carrying the canonical note markers; Driver/phone is the courier;
 *   DP / Cicilan 1 / Pelunasan + dates -> payment_transactions rows carrying
 *     the canonical persisted markers (raw_payload.note = down_payment /
 *     dp_settlement) with status='paid' (PaymentService — unverified money is
 *     NEVER counted). Absent marker => blank, NOT 0 (no fabricated zeros).
 *   Driver / phone -> couriers + the courier's user row (Owner: Driver=Kurir);
 *     self_sub resolves the delivering SKS user and that user's phone.
 *   Hampers -> product CATEGORY (DEV master data has 'Hampers'); values follow
 *     SV2's enum ('ya'/'tidak').
 *   Diskon -> stored order-level voucher discount (historical; product
 *     discounts are already baked into unit_price_snapshot — never recomputed
 *     from current promo configuration).
 *
 * Cancelled orders: rows are KEPT with real values (order_status tells the
 * clerk); the canonical outstanding is 0 for cancelled rows via
 * CollectibleReceivable (WS-7), so no money column needs blanking.
 */
class OrderStaffWorksheetService
{
    public const COLUMNS = [
        'no' => 'No',
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
        'order_discount' => 'Diskon',
        'invoice_total' => 'Total Pembayaran',
        'invoice_paid' => 'Total Bayar',
        'invoice_outstanding' => 'Sisa',
        'payment_method' => 'Metode Pembayaran',
        'payment_status' => 'Status Pembayaran (Saat Transaksi)',
        'money_position' => 'Posisi Uang (Saat Transaksi)',
        'update_payment_status' => 'Update Status Pembayaran',
        'update_money_position' => 'Update Posisi Uang',
        'dp' => 'DP',
        'installment_1' => 'Cicilan 1',
        'installment_1_date' => 'Tgl Cicilan 1',
        'settlement' => 'Pelunasan',
        'settlement_date' => 'Tgl Pelunasan',
        'settlement_note' => 'Keterangan Pelunasan',
        'settlement_total' => 'Total Bayar Pelunasan',
        'delivery_date' => 'Tgl Kirim',
        'shipping_address' => 'Alamat Kirim',
        'maps_link' => 'Wilayah Kirim',
        'recipient_name' => 'Nama Penerima',
        'recipient_phone' => 'No. Hp Penerima',
        'order_notes' => 'Catatan',
        'shipping_status' => 'Status Pengiriman',
        'driver_name' => 'Driver',
        'driver_phone' => 'No Telp. Driver',
        'hampers' => 'Hampers',
        'province' => 'Provinsi',
        'regency' => 'Kota/Kabupaten',
        'district' => 'Kecamatan',
        'village' => 'Kelurahan/Desa',
        'order_status' => 'Status Pesanan',
    ];

    /**
     * Staff-owned columns after the Owner's decisions (2026-10-29):
     * ONLY the three columns below have no automated source. `money_position`
     * is now DERIVED from the payment method (COD -> konsumen; bank transfer /
     * DP -> keuangan), `update_payment_status` is SYSTEM (latest canonical
     * payment status), DP/cicilan/pelunasan/driver/hampers are DERIVED/SYSTEM.
     * `update_money_position` remains the MANUAL CURRENT-custody column
     * (konsumen | keuangan | sales | korsal) — initial position is NEVER manual.
     */
    public const MANUAL_FIELDS = [
        'update_money_position', 'settlement_note', 'settlement_total',
    ];

    /**
     * Owner decision 2026-10-29: "Posisi Uang" names who HOLDS or is
     * responsible for the payment money — exactly these four values. The
     * legacy SimpleV2 bank-account buckets and `admin` are not categories.
     * Initial position (`money_position`) is DERIVED from the payment method;
     * CURRENT custody (`update_money_position`) is the protected MANUAL cell
     * validated against this list as a real Sheets dropdown.
     */
    public const MONEY_POSITIONS = ['konsumen', 'keuangan', 'sales', 'korsal'];

    public const MONEY_POSITION_FIELDS = ['money_position', 'update_money_position'];

    /** Computed/mapped from other PrimeClassy data (not a plain column copy). */
    public const DERIVED_FIELDS = [
        'money_position', 'korsal_name', 'sales_name', 'invoice_outstanding', 'dp', 'installment_1', 'installment_1_date',
        'settlement', 'settlement_date', 'driver_name', 'driver_phone', 'hampers', 'maps_link', 'line_total',
    ];

    /** SimpleV2 sends cake products to CAKE_2026 and every other category to COOKIES_2026. */
    public const CATEGORY_GROUPS = ['cookies', 'cake'];

    public static function classification(string $field): string
    {
        if (in_array($field, self::MANUAL_FIELDS, true)) {
            return 'MANUAL';
        }

        return in_array($field, self::DERIVED_FIELDS, true) ? 'DERIVED' : 'SYSTEM';
    }

    public const NUMERIC_FIELDS = [
        'unit_price', 'quantity', 'line_total', 'shipping_fee', 'order_discount',
        'invoice_total', 'invoice_paid', 'invoice_outstanding', 'dp', 'installment_1', 'settlement',
    ];

    public function query(?int $agentId, ?array $period, ?string $categoryGroup = null): Builder
    {
        // Same hierarchy-aware Sales/Korsal resolution as the canonical
        // transaction report (OrderTransactionReportService:30-32).
        $salesName = "CASE WHEN customer_roles.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN customers.name ELSE COALESCE(sales_users.name, korsal_users.name, agent_users.name) END";
        $korsalName = "CASE WHEN customer_roles.slug = 'agen' THEN NULL WHEN customer_roles.slug = 'korsal' THEN customers.name ELSE korsal_users.name END";
        // Invoice-level money lands only on the invoice's first item row so a
        // column SUM in the sheet counts each invoice once (Laporan A rule).
        $first = 'ROW_NUMBER() OVER (PARTITION BY i.order_id ORDER BY i.id) = 1';

        $query = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('users as customers', 'customers.id', '=', 'o.konsumen_id')
            ->leftJoin('roles as customer_roles', 'customer_roles.id', '=', 'customers.role_id')
            ->leftJoin('users as sales_users', 'sales_users.id', '=', 'o.sales_id')
            ->leftJoin('users as korsal_users', 'korsal_users.id', '=', 'o.korsal_id')
            ->leftJoin('users as agent_users', 'agent_users.id', '=', 'o.agent_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'o.payment_method_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'i.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'pr.category_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 'i.shipment_id')
            ->leftJoin('couriers as c', 'c.id', '=', 'sh.courier_id')
            ->leftJoin('users as courier_users', 'courier_users.id', '=', 'c.user_id')
            ->leftJoin('users as self_delivered_users', 'self_delivered_users.id', '=', 'sh.self_delivered_by_user_id')
            ->selectRaw('i.id as no')
            ->selectRaw('o.order_no as invoice_no')
            ->selectRaw("DATE_FORMAT(o.created_at, '%d/%m/%Y') as order_date")
            ->selectRaw('agent_users.name as agent_name')
            ->selectRaw("($korsalName) as korsal_name")
            ->selectRaw("($salesName) as sales_name")
            ->selectRaw('sales_users.phone as sales_phone')
            ->selectRaw("COALESCE(NULLIF(o.recipient_name_snapshot, ''), customers.name, '-') as customer_name")
            ->selectRaw('customers.phone as customer_phone')
            ->selectRaw('o.address_snapshot as customer_address')
            ->selectRaw("COALESCE(NULLIF(i.sku_snapshot, ''), '-') as sku")
            ->selectRaw("CASE WHEN i.variation_label_snapshot IS NULL OR i.variation_label_snapshot = '' THEN i.product_name_snapshot ELSE CONCAT(i.product_name_snapshot, ' - ', i.variation_label_snapshot) END as product_name")
            ->selectRaw('pc.name as category_name')
            ->selectRaw('i.unit_price_snapshot + 0 as unit_price')
            ->selectRaw('i.fulfilled_quantity + 0 as quantity')
            ->selectRaw('i.subtotal_snapshot + 0 as line_total')
            ->selectRaw("CASE WHEN $first THEN o.shipping_fee_amount + 0 ELSE NULL END as shipping_fee")
            ->selectRaw("CASE WHEN $first THEN o.discount_amount + 0 ELSE NULL END as order_discount")
            ->selectRaw("CASE WHEN $first THEN o.total_amount + 0 ELSE NULL END as invoice_total")
            ->selectRaw("CASE WHEN $first THEN o.paid_amount + 0 ELSE NULL END as invoice_paid")
            ->selectRaw("CASE WHEN $first THEN ".\App\Support\CollectibleReceivable::sql('o')." ELSE NULL END as invoice_outstanding")
            ->selectRaw('pm.name as payment_method')
            ->selectRaw('o.payment_status as payment_status')
            // POSISI UANG SAAT TRANSAKSI — DERIVED from the payment method
            // (Owner: COD -> konsumen; bank transfer/DP -> keuangan). Unknown
            // methods stay NULL (explicit unresolved, never invented).
            ->selectRaw("CASE WHEN pm.type = 'cod' THEN 'konsumen' WHEN pm.code IN ('bank_transfer','down_payment') THEN 'keuangan' ELSE NULL END as money_position")
            // UPDATE STATUS PEMBAYARAN — SYSTEM: the latest canonical order
            // payment status (never the initial snapshot, never manual input).
            ->selectRaw('o.payment_status as update_payment_status')
            ->selectRaw("'' as update_money_position")
            ->selectRaw("'' as settlement_note")
            ->selectRaw("'' as settlement_total")
            // DERIVED money columns from canonical payment markers; blank (NULL)
            // when the marker never occurred — never a fabricated 0.
            ->selectRaw("CASE WHEN $first THEN (SELECT SUM(pt.amount) FROM payment_transactions pt WHERE pt.order_id = o.id AND pt.type = 'payment' AND pt.status = 'paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'\$.note')) = 'down_payment') ELSE NULL END as dp")
            ->selectRaw("CASE WHEN $first THEN (SELECT pt.amount FROM payment_transactions pt WHERE pt.order_id = o.id AND pt.type = 'payment' AND pt.status = 'paid' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'\$.note')),'') NOT IN ('down_payment','dp_settlement','cash_on_delivery') ORDER BY pt.paid_at, pt.id LIMIT 1) ELSE NULL END as installment_1")
            ->selectRaw("CASE WHEN $first THEN (SELECT DATE_FORMAT(pt.paid_at, '%d/%m/%Y') FROM payment_transactions pt WHERE pt.order_id = o.id AND pt.type = 'payment' AND pt.status = 'paid' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'\$.note')),'') NOT IN ('down_payment','dp_settlement','cash_on_delivery') ORDER BY pt.paid_at, pt.id LIMIT 1) ELSE NULL END as installment_1_date")
            ->selectRaw("CASE WHEN $first THEN (SELECT SUM(pt.amount) FROM payment_transactions pt WHERE pt.order_id = o.id AND pt.type = 'payment' AND pt.status = 'paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'\$.note')) = 'dp_settlement') ELSE NULL END as settlement")
            ->selectRaw("CASE WHEN $first THEN (SELECT DATE_FORMAT(MAX(pt.paid_at), '%d/%m/%Y') FROM payment_transactions pt WHERE pt.order_id = o.id AND pt.type = 'payment' AND pt.status = 'paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'\$.note')) = 'dp_settlement') ELSE NULL END as settlement_date")
            ->selectRaw("COALESCE(DATE_FORMAT(i.requested_delivery_date, '%d/%m/%Y'), '-') as delivery_date")
            ->selectRaw('o.address_snapshot as shipping_address')
            ->selectRaw("CASE WHEN $first THEN CONCAT('https://maps.google.com/?q=', o.latitude_snapshot, ',', o.longitude_snapshot) ELSE NULL END as maps_link")
            ->selectRaw('o.recipient_name_snapshot as recipient_name')
            ->selectRaw('o.recipient_phone_snapshot as recipient_phone')
            ->selectRaw('o.notes as order_notes')
            ->selectRaw('sh.status as shipping_status')
            ->selectRaw("CASE WHEN sh.delivery_mode = 'self_sub' THEN self_delivered_users.name ELSE c.name END as driver_name")
            // Owner mapping 2026-10-29: Driver = Kurir — phone resolves through the
            // courier's user row (self_sub: the delivering SKS user). Blank when
            // no courier is assigned yet (correct per contract, not manual).
            ->selectRaw("CASE WHEN sh.delivery_mode = 'self_sub' THEN self_delivered_users.phone ELSE courier_users.phone END as driver_phone")
            // Owner mapping: Hampers is a PRODUCT CATEGORY (DEV master data:
            // Cookies | Cake | Hampers). Values follow SV2's enum.
            ->selectRaw("CASE WHEN LOWER(TRIM(pc.name)) = 'hampers' THEN 'ya' ELSE 'tidak' END as hampers")
            ->selectRaw("COALESCE(o.province_snapshot, '-') as province")
            ->selectRaw("COALESCE(o.regency_snapshot, '-') as regency")
            ->selectRaw("COALESCE(o.district_snapshot, '-') as district")
            ->selectRaw("COALESCE(o.village_snapshot, '-') as village")
            ->selectRaw('o.status as order_status');

        if ($agentId !== null) {
            $query->where('o.agent_id', $agentId);
        }
        // Same empty-guard semantics as Laporan B (resolve() may return null or
        // an array with null bounds when no period filter is configured).
        if (! empty($period['from'])) {
            $query->where('o.created_at', '>=', $period['from']);
        }
        if (! empty($period['to'])) {
            $query->where('o.created_at', '<', $period['to']);
        }

        if ($categoryGroup === 'cake') {
            $query->whereRaw("LOWER(COALESCE(pc.name, '')) LIKE '%cake%'");
        } elseif ($categoryGroup === 'cookies') {
            $query->whereRaw("LOWER(COALESCE(pc.name, '')) NOT LIKE '%cake%'");
        }

        return $query->orderByDesc('o.created_at')->orderBy('o.order_no')->orderBy('i.id');
    }
}
