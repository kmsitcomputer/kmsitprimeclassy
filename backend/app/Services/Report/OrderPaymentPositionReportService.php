<?php

namespace App\Services\Report;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan B — Posisi Pembayaran Terkini.
 *
 * One row per invoice (one order = one invoice in PrimeClassy) reflecting the
 * CURRENT state of the order. This report deliberately reads live values: the
 * database stays the single source of truth and Google Sheets is a one-way
 * export target.
 *
 * Money rules:
 * - "Sisa Piutang" is the canonical collectible receivable (M-2,
 *   `CollectibleReceivable`): cancelled orders contribute 0, otherwise
 *   max(0, total_amount - paid_amount). It never reads
 *   `orders.remaining_amount`, which stays 0 for every non-DP order that
 *   has never been verified and would therefore understate the receivable.
 * - No payment/refund/business rule is changed here; this is a read model.
 */
class OrderPaymentPositionReportService
{
    /** The locked 15-column contract of Laporan B (no "Posisi Uang" column). */
    public const COLUMNS = [
        'invoice_no' => 'No Faktur',
        'order_date' => 'Tanggal Order',
        'agn' => 'AGN',
        'agent_name' => 'Agen',
        'korsal_name' => 'Korsal',
        'sales_name' => 'Sales',
        'customer_name' => 'Konsumen',
        'invoice_total' => 'Total Faktur',
        'paid_amount' => 'Total Dibayar',
        'outstanding_amount' => 'Sisa Piutang',
        'payment_method' => 'Metode Pembayaran',
        'payment_status' => 'Status Pembayaran',
        'last_paid_at' => 'Tanggal Pembayaran Terakhir',
        'order_status' => 'Status Order',
        'updated_at' => 'Terakhir Diperbarui',
    ];

    /** Fields that must reach the sheet as numbers, not text. */
    public const NUMERIC_FIELDS = ['agn', 'invoice_total', 'paid_amount', 'outstanding_amount'];

    /**
     * @param  array{from?:?string,to?:?string}  $filters  UTC window (`to` exclusive), resolved by ReportPeriodFilter
     */
    public function query(?int $agentId = null, array $filters = []): Builder
    {
        $query = DB::table('orders as o')
            ->leftJoin('users as agent_users', 'agent_users.id', '=', 'o.agent_id')
            ->leftJoin('users as korsal_users', 'korsal_users.id', '=', 'o.korsal_id')
            ->leftJoin('users as sales_users', 'sales_users.id', '=', 'o.sales_id')
            ->leftJoin('users as konsumen_users', 'konsumen_users.id', '=', 'o.konsumen_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'o.payment_method_id')
            ->selectRaw('o.order_no as invoice_no')
            ->selectRaw("DATE_FORMAT(o.created_at, '%d/%m/%Y') as order_date")
            ->selectRaw('o.agent_id + 0 as agn')
            ->selectRaw("COALESCE(NULLIF(agent_users.name, ''), '-') as agent_name")
            ->selectRaw("COALESCE(NULLIF(korsal_users.name, ''), '-') as korsal_name")
            ->selectRaw("COALESCE(NULLIF(sales_users.name, ''), '-') as sales_name")
            ->selectRaw("COALESCE(NULLIF(o.recipient_name_snapshot, ''), NULLIF(konsumen_users.name, ''), '-') as customer_name")
            ->selectRaw('o.total_amount + 0 as invoice_total')
            ->selectRaw('COALESCE(o.paid_amount, 0) + 0 as paid_amount')
            ->selectRaw(\App\Support\CollectibleReceivable::sql('o').' + 0 as outstanding_amount')
            ->selectRaw("COALESCE(NULLIF(pm.name, ''), NULLIF(pm.code, ''), '-') as payment_method")
            ->selectRaw('o.payment_status as payment_status')
            ->selectRaw("COALESCE(DATE_FORMAT((SELECT MAX(pt.paid_at) FROM payment_transactions pt WHERE pt.order_id = o.id), '%d/%m/%Y %H:%i'), '-') as last_paid_at")
            ->selectRaw('o.status as order_status')
            ->selectRaw("COALESCE(DATE_FORMAT(o.updated_at, '%d/%m/%Y %H:%i'), '-') as updated_at");

        // Isolation is keyed on the agent recorded on the order.
        if ($agentId !== null) {
            $query->where('o.agent_id', $agentId);
        }
        // Boundaries are already UTC instants (WIB calendar converted once by
        // ReportPeriodFilter); `to` is exclusive, so the last WIB day is whole.
        if (! empty($filters['from'])) {
            $query->where('o.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('o.created_at', '<', $filters['to']);
        }

        return $query->orderByDesc('o.created_at')->orderBy('o.order_no');
    }
}
