<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\Export\ExcelExportService;
use App\Services\Report\OrderTransactionReportService;
use App\Services\Report\ReportService;
use App\Support\HumanDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * Management reports (Blueprint §Reports) — every action here is read-only.
 * Route-level role gating (super_admin/agen/admin, fees/agent narrower still)
 * lives in routes/api_v1.php; ReportService re-confines every query to the
 * actor's own agent branch regardless, as a second layer.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly ExcelExportService $excelExportService,
    ) {}

    private function filters(Request $request): array
    {
        return $request->only([
            'from', 'to', 'delivery_date_from', 'delivery_date_to',
            'sales_id', 'korsal_id', 'courier_id', 'status', 'item_status', 'order_status', 'search',
            // Report dimensions for the consolidated network summary.
            'payment_method', 'product_id',
            // super_admin-only narrowing — see ReportService::scopeToActor's
            // docblock; every other actor's own agent_id filter is never
            // overridable by this, no matter what a request sends.
            'agent_id',
        ]);
    }

    /** Shared by every roster-style report (customers/korsal/sales/couriers): JSON paginates, xlsx exports the full filtered set. */
    private function respondPaginatedOrExport(Request $request, Builder|QueryBuilder $query, string $filename, array $headers, \Closure $rowMapper)
    {
        if ($request->string('export')->toString() === 'xlsx') {
            return $this->excelExportService->streamXlsx($filename, $headers, $query->get()->map($rowMapper));
        }

        $paginated = $query->paginate($request->integer('per_page', 15));

        return $this->ok($paginated->items(), meta: [
            'current_page' => $paginated->currentPage(), 'last_page' => $paginated->lastPage(), 'total' => $paginated->total(),
        ]);
    }

    public function transactions(Request $request)
    {
        return $this->respondPaginatedOrExport(
            $request,
            $this->reportService->transactions($request->user(), $this->filters($request)),
            'laporan-transaksi.xlsx',
            array_values(OrderTransactionReportService::COLUMNS),
            fn ($r) => array_map(fn ($field) => $r->$field, array_keys(OrderTransactionReportService::COLUMNS)),
        );
    }

    /**
     * Network transaction totals (Owner 2026-10-29 §7): role-scoped network,
     * order-grained (no double count), TANPA SALES/KORSAL groups kept visible.
     */
    public function networkTransactions(Request $request, \App\Services\Report\NetworkTransactionReportService $service)
    {
        $report = $service->report($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            $rows = [];
            $t = $report['totals'];
            $rows[] = ['TOTAL', '', 'Jaringan: '.$report['scope'], $t['order_count'], $t['total_amount'], $t['paid_amount'], $t['outstanding_amount'], ''];
            foreach ($report['by_status'] as $s) {
                $rows[] = ['STATUS', '', $s['status'], $s['order_count'], $s['total_amount'], '', '', $s['status']];
            }
            foreach (($report['by_korsal'] ?? []) as $k) {
                $rows[] = ['KORSAL', $k['id'] ?? '', $k['name'], $k['order_count'], $k['total_amount'], $k['paid_amount'], $k['outstanding_amount'], ''];
            }
            foreach (($report['by_sales'] ?? []) as $s2) {
                $rows[] = ['SALES', $s2['id'] ?? '', $s2['name'], $s2['order_count'], $s2['total_amount'], $s2['paid_amount'], $s2['outstanding_amount'], ''];
            }

            return $this->excelExportService->streamXlsx(
                'laporan-jaringan-transaksi.xlsx',
                ['Bagian', 'ID', 'Nama', 'Jumlah Order', 'Nilai Total', 'Dibayar', 'Outstanding', 'Status'],
                collect($rows),
            );
        }

        return $this->ok($report);
    }

    /** CRM visit funnel 0–7/N (SimpleV2 semantics verbatim; see CrmVisitReportService). */
    public function crmVisits(Request $request, \App\Services\Report\CrmVisitReportService $service)
    {
        $report = $service->funnel($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            $rows = [];
            foreach ($report['rows'] as $r) {
                $rows[] = array_merge([$r['owner_name'], $r['customers'], $r['visits'], $r['rk']], array_values($r['buckets']));
            }
            $totals = $report['totals'];
            $rows[] = array_merge(['— TOTAL —', $totals['customers'], $totals['visits'], $totals['rk']], array_values($totals['buckets']));

            return $this->excelExportService->streamXlsx(
                'laporan-kunjungan-crm.xlsx',
                ['Sales/Pemilik', 'JML Kons', 'JML KJ', 'JML RK', '0%', 'Nolak', 'BS', 'MS', 'IP', 'IH', 'MK', 'MKK', 'Deal'],
                collect($rows),
            );
        }

        return $this->ok($report);
    }

    public function salesKorsalFees(Request $request)
    {
        $data = $this->reportService->salesKorsalFees($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            $rows = collect($data['sales'])->map(fn ($r) => ['Sales', $r->sales_name, $r->transaction_count, $r->total_fee])
                ->concat(collect($data['korsal'])->map(fn ($r) => ['Korsal', $r->korsal_name, $r->transaction_count, $r->total_fee]));

            return $this->excelExportService->streamXlsx(
                'laporan-fee-sales-korsal.xlsx',
                ['Role', 'Nama', 'Transaksi Berkomisi', 'Total Fee'],
                $rows
            );
        }

        return $this->ok($data);
    }

    public function cancellationsRefunds(Request $request)
    {
        $data = $this->reportService->cancellationsRefunds($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            $rows = collect($data['cancellations'])->map(fn ($o) => [
                'Batal', $o->order_no, HumanDate::date($o->cancelled_at), $o->buyerName(), $o->cancellation_reason, '-',
            ])
                ->concat(collect($data['adjustments'])->map(fn ($a) => [
                    'Batal Sebagian (Refund '.($a->refund_status === 'pending' ? 'Pending' : 'Selesai').')',
                    $a->order_no, HumanDate::date($a->created_at),
                    $a->product_name_snapshot.' (SKU: '.($a->product_sku ?? '-').')', $a->reason, $a->refund_amount,
                ]))
                ->concat(collect($data['returns'])->map(fn ($r) => [
                    'Request Refund ('.$r->refund_status.')', $r->order_no, HumanDate::date($r->created_at),
                    $r->product_name_snapshot.' (SKU: '.($r->product_sku ?? '-').') — Kurir: '.($r->courier_name ?? '-'), $r->reason, $r->refund_amount,
                ]));

            return $this->excelExportService->streamXlsx(
                'laporan-batal-refund.xlsx',
                ['Kategori', 'Order No', 'Tanggal', 'Detail', 'Alasan', 'Jumlah'],
                $rows
            );
        }

        return $this->ok($data);
    }

    public function courierFees(Request $request)
    {
        $rows = $this->reportService->courierFees($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            return $this->excelExportService->streamXlsx(
                'laporan-fee-kurir.xlsx',
                ['Kurir', 'Jumlah Pengiriman', 'Total Fee'],
                $rows->map(fn ($r) => [$r->courier_name, $r->delivery_count, $r->total_fee])
            );
        }

        return $this->ok($rows);
    }

    public function agentFees(Request $request)
    {
        $rows = $this->reportService->agentFees($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            return $this->excelExportService->streamXlsx(
                'laporan-fee-agen.xlsx',
                ['Agen', 'Transaksi Berkomisi', 'Jumlah Sales Aktif', 'Total Fee'],
                $rows->map(fn ($r) => [$r->agent_name, $r->transaction_count, $r->active_sales_count, $r->total_fee])
            );
        }

        return $this->ok($rows);
    }

    public function paymentStatus(Request $request)
    {
        $rows = $this->reportService->paymentStatus($request->user(), $this->filters($request));

        if ($request->string('export')->toString() === 'xlsx') {
            return $this->excelExportService->streamXlsx(
                'laporan-status-pembayaran.xlsx',
                ['Agen', 'Status Pembayaran', 'Jumlah Order', 'Total Nilai'],
                $rows->map(fn ($r) => [$r->agent_name, $r->payment_status, $r->order_count, $r->total_amount])
            );
        }

        return $this->ok($rows);
    }

    public function financeSummary(Request $request)
    {
        return $this->ok($this->reportService->financeSummary($request->user(), $this->filters($request)));
    }

    /**
     * R-04 / §G: per-order canonical finance projection — one row per order, figures straight from
     * PaymentSummaryService (no competing payment truth, no duplication by items/transactions).
     */
    public function financeOrders(Request $request)
    {
        if ($request->string('export')->toString() === 'xlsx') {
            // Payment-ledger parity (SV2 prime-payments): full filtered set,
            // one canonical row per order. Date filters apply independently
            // (unlike the SV2 PaymentExport::byDate defect — both bounds or a
            // single bound work; no 500 path exists here).
            $result = $this->reportService->financeOrders(
                $request->user(),
                $this->filters($request) + ['per_page' => 10000],
            );
            $rows = array_map(fn ($o) => [
                $o['order_no'], $o['customer'], $o['order_date'], $o['payment_method'],
                $o['grand_total'], $o['dp_submitted'], $o['dp_paid'], $o['total_paid'], $o['remaining'],
                $o['payment_status'], $o['verification_status'] ?? '', $o['has_pending_proof'] ? 'ya' : 'tidak',
                $o['refund'], $o['additional_payment'],
                implode('; ', array_map(fn ($role, $amount) => "$role=$amount", array_keys($o['fees'] ?? []), array_values($o['fees'] ?? []))),
            ], $result['orders']);

            return $this->excelExportService->streamXlsx(
                'laporan-keuangan-order.xlsx',
                ['No Faktur', 'Pelanggan', 'Tanggal', 'Metode', 'Grand Total', 'DP Diajukan', 'DP Dibayar',
                    'Total Dibayar', 'Sisa', 'Status Pembayaran', 'Verifikasi', 'Bukti Pending', 'Refund',
                    'Additional', 'Fee (role=nominal; hanya yang berhak lihat)'],
                collect($rows),
            );
        }

        $result = $this->reportService->financeOrders(
            $request->user(),
            $this->filters($request) + ['per_page' => $request->integer('per_page', 15)],
        );

        return $this->ok($result['orders'], meta: $result['meta']);
    }

    public function couriersPerAgent(Request $request)
    {
        return $this->ok($this->reportService->couriersPerAgent($request->user(), $this->filters($request)));
    }

    public function customers(Request $request)
    {
        return $this->respondPaginatedOrExport(
            $request,
            $this->reportService->customersReport($request->user(), $this->filters($request)),
            'laporan-pelanggan.xlsx',
            ['Nama', 'Telepon', 'Jumlah Order', 'Nilai Order', 'Terbayar', 'Order Terakhir'],
            fn ($r) => [$r->konsumen_name, $r->konsumen_phone, $r->order_count, $r->total_ordered, $r->total_paid, HumanDate::date($r->last_order_at)],
        );
    }

    public function korsalRoster(Request $request)
    {
        return $this->respondPaginatedOrExport(
            $request,
            $this->reportService->korsalReport($request->user(), $this->filters($request)),
            'laporan-korsal.xlsx',
            ['Nama', 'Telepon', 'Status', 'Jumlah Sales Aktif', 'Jumlah Transaksi', 'Total Fee Sales'],
            fn ($r) => [$r->korsal_name, $r->korsal_phone, $r->status, $r->active_sales_count, $r->transaction_count, $r->total_sales_fee],
        );
    }

    public function salesRoster(Request $request)
    {
        return $this->respondPaginatedOrExport(
            $request,
            $this->reportService->salesReport($request->user(), $this->filters($request)),
            'laporan-sales.xlsx',
            ['Nama', 'Telepon', 'Korsal', 'Status', 'Jumlah Transaksi', 'Jumlah Konsumen', 'Total Fee'],
            fn ($r) => [$r->sales_name, $r->sales_phone, $r->korsal_name, $r->status, $r->transaction_count, $r->customer_count, $r->total_fee],
        );
    }

    public function courierRoster(Request $request)
    {
        return $this->respondPaginatedOrExport(
            $request,
            $this->reportService->courierReport($request->user(), $this->filters($request)),
            'laporan-kurir.xlsx',
            ['Nama', 'Aktif', 'Jumlah Pengiriman', 'Jumlah Return', 'Total Fee'],
            fn ($r) => [$r->courier_name, $r->is_active ? 'Ya' : 'Tidak', $r->delivery_count, $r->return_count, $r->total_fee],
        );
    }

    public function dashboardSummary(Request $request)
    {
        return $this->ok($this->reportService->dashboardSummary($request->user(), $this->filters($request)));
    }

    /**
     * Consolidated Super Admin (req 11) / Agen (req 12) / Keuangan dashboard
     * summary — headcounts, per-agen rollup, products, payment-method split,
     * paid vs outstanding money and refund/additional-payment totals, all
     * scoped server-side by ReportService::networkSummary.
     */
    public function networkSummary(Request $request)
    {
        return $this->ok($this->reportService->networkSummary($request->user(), $this->filters($request)));
    }

    /** Sales-only: every konsumen they've sold to, with order count + fee earned from that konsumen. */
    public function salesCustomers(Request $request)
    {
        $actor = $request->user();
        $filters = $this->filters($request);
        $query = $this->reportService->salesCustomersReport($actor, $filters);

        if ($request->string('export')->toString() === 'xlsx') {
            return $this->excelExportService->streamXlsx(
                'laporan-konsumen-sales.xlsx',
                ['Nama Konsumen', 'Telepon', 'Jumlah Order', 'Total Fee'],
                $query->get()->map(fn ($r) => [$r->konsumen_name, $r->konsumen_phone, $r->order_count, $r->total_fee]),
            );
        }

        $paginated = $query->paginate($request->integer('per_page', 15));

        return $this->ok($paginated->items(), meta: [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            // The grouped query's own count IS the distinct-konsumen total — no extra query needed.
            'total' => $paginated->total(),
            'total_fee' => $this->reportService->salesCustomersFeeTotal($actor, $filters),
        ]);
    }
}
