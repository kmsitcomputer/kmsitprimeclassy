<?php

namespace App\Services\GoogleSheets;

use App\Services\Report\OrderTransactionReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DatasetRegistry
{
    public function __construct(private readonly OrderTransactionReportService $orderTransactionReport) {}

    /** Public keys never become arbitrary SQL identifiers. */
    public function definitions(): array
    {
        return [
            'products' => ['id', 'sku', 'product_name', 'price', 'status'],
            'stock' => ['id', 'sku', 'product_name', 'quantity', 'reserved_quantity'],
            'transactions' => array_keys(OrderTransactionReportService::COLUMNS),
            // Backward-compatible dataset key; both now use the same canonical item-level rows.
            'transaction_items' => array_keys(OrderTransactionReportService::COLUMNS),
            'sales' => ['id', 'name', 'korsal_id', 'status'],
            'korsal' => ['id', 'name', 'status'],
            'courier_deliveries' => ['id', 'order_no', 'courier_id', 'tracking_number', 'status', 'delivered_at'],
            'sales_fees' => ['id', 'order_no', 'beneficiary_id', 'amount', 'status', 'earned_at'],
            // Existing reporting treats Korsal fee as the sum of downline Sales commissions.
            'korsal_fees' => ['korsal_id', 'korsal_name', 'amount'],
            'courier_fees' => ['id', 'order_no', 'beneficiary_id', 'amount', 'status', 'earned_at'],
            'payment_status' => ['id', 'order_no', 'payment_status', 'paid_amount', 'remaining_amount'],
            'refunds' => ['id', 'order_no', 'refund_amount', 'refund_status'],
            'additional_payments' => ['id', 'order_no', 'amount', 'method', 'status'],
            'transaction_report' => ['total_orders', 'paid_orders', 'pending_orders', 'cancelled_orders', 'gross_revenue'],
            'financial_summary' => ['transaction_count', 'gross_revenue', 'paid_amount', 'outstanding_amount', 'refund_amount', 'additional_payment_amount', 'agent_fee', 'sales_fee', 'courier_fee'],
        ];
    }

    public function defaults(): array
    {
        $columns = collect(OrderTransactionReportService::COLUMNS)
            ->map(fn (string $label, string $field) => compact('field', 'label'))->values()->all();

        return ['transactions' => $columns, 'transaction_items' => $columns];
    }

    public function query(string $dataset, ?int $agentId, array $filters = []): Builder
    {
        if (! isset($this->definitions()[$dataset])) {
            throw ValidationException::withMessages(['dataset' => 'Dataset tidak diizinkan.']);
        }
        if (in_array($dataset, ['transactions', 'transaction_items'], true)) {
            $canonical = $this->orderTransactionReport->query($agentId, null, [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'delivery_date_from' => $filters['delivery_date_from'] ?? null,
                'delivery_date_to' => $filters['delivery_date_to'] ?? null,
                'sales_id' => $filters['sales_id'] ?? null,
                'korsal_id' => $filters['korsal_id'] ?? null,
                'courier_id' => $filters['courier_id'] ?? null,
                'item_status' => $filters['item_status'] ?? $filters['status'] ?? null,
                'order_status' => $filters['order_status'] ?? null,
            ]);

            // A derived table preserves canonical aliases while allowing SyncService
            // to select an administrator's chosen subset and column order safely.
            return DB::query()->fromSub($canonical, 'canonical_transactions');
        }
        if (in_array($dataset, ['products', 'stock'])) {
            $q = DB::table('products as p')->whereNull('p.deleted_at');
            if ($dataset === 'products') {
                $q->select(['p.id', 'p.sku', 'p.name as product_name', 'p.base_price as price', 'p.status']);
                if ($agentId !== null) {
                    $q->where(function ($q) use ($agentId) {
                        $q->whereExists(fn ($s) => $s->selectRaw('1')->from('product_stocks as ps')->whereColumn('ps.product_id', 'p.id')->where('ps.agent_id', $agentId))
                            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('product_variations as pv')->join('product_variation_stocks as vs', 'vs.product_variation_id', '=', 'pv.id')->whereColumn('pv.product_id', 'p.id')->where('vs.agent_id', $agentId));
                    });
                }
            } else {
                $q->join('product_stocks as s', 's.product_id', '=', 'p.id')->select(['s.id', 'p.sku', 'p.name as product_name', 's.quantity_on_hand as quantity', 's.quantity_reserved as reserved_quantity']);
                if ($agentId !== null) {
                    $q->where('s.agent_id', $agentId);
                }
                $variants = DB::table('product_variation_stocks as s')->join('product_variations as v', 'v.id', '=', 's.product_variation_id')->join('products as p', 'p.id', '=', 'v.product_id')->whereNull('v.deleted_at')->whereNull('p.deleted_at')->select(['s.id', 'v.sku', 'p.name as product_name', 's.quantity_on_hand as quantity', 's.quantity_reserved as reserved_quantity']);
                if ($agentId !== null) {
                    $variants->where('s.agent_id', $agentId);
                }
                $q->unionAll($variants);
            }
        } elseif (in_array($dataset, ['sales', 'korsal'])) {
            $q = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->where('r.slug', $dataset)->whereNull('u.deleted_at')->select($dataset === 'sales' ? ['u.id', 'u.name', 'u.korsal_id', 'u.status'] : ['u.id', 'u.name', 'u.status']);
            if ($agentId !== null) {
                $q->where('u.agent_id', $agentId);
            }
        } elseif ($dataset === 'korsal_fees') {
            $q = DB::table('commissions as c')->join('users as s', 's.id', '=', 'c.beneficiary_user_id')->join('users as k', 'k.id', '=', 's.korsal_id')->where('c.beneficiary_role', 'sales')->select(['k.id as korsal_id', 'k.name as korsal_name'])->selectRaw('SUM(c.amount) as amount')->groupBy('k.id', 'k.name');
            if ($agentId !== null) {
                $q->where('k.agent_id', $agentId)->where('s.agent_id', $agentId);
            }
        } elseif ($dataset === 'transaction_report') {
            $q = DB::table('orders as o')
                ->selectRaw('COUNT(*) as total_orders')
                ->selectRaw("SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_orders")
                ->selectRaw("SUM(CASE WHEN o.payment_status IN ('unpaid', 'partially_paid') THEN 1 ELSE 0 END) as pending_orders")
                ->selectRaw("SUM(CASE WHEN o.status = 'dibatalkan' THEN 1 ELSE 0 END) as cancelled_orders")
                ->selectRaw('COALESCE(SUM(o.total_amount), 0) as gross_revenue');
            if ($agentId !== null) {
                $q->where('o.agent_id', $agentId);
            }
        } elseif ($dataset === 'financial_summary') {
            $refunds = DB::table('return_items as r')->join('order_items as i', 'i.id', '=', 'r.order_item_id')->join('orders as ro', 'ro.id', '=', 'i.order_id')->selectRaw('COALESCE(SUM(r.refund_amount), 0)');
            $additional = DB::table('order_additional_payments as a')->join('orders as ao', 'ao.id', '=', 'a.order_id')->selectRaw('COALESCE(SUM(a.amount), 0)');
            $agentFees = DB::table('commissions as ac')->join('orders as afo', 'afo.id', '=', 'ac.order_id')->where('ac.beneficiary_role', 'agent')->selectRaw('COALESCE(SUM(ac.amount), 0)');
            $salesFees = DB::table('commissions as sc')->join('orders as sfo', 'sfo.id', '=', 'sc.order_id')->where('sc.beneficiary_role', 'sales')->selectRaw('COALESCE(SUM(sc.amount), 0)');
            $courierFees = DB::table('commissions as cc')->join('orders as cfo', 'cfo.id', '=', 'cc.order_id')->where('cc.beneficiary_role', 'courier')->selectRaw('COALESCE(SUM(cc.amount), 0)');
            if ($agentId !== null) {
                $refunds->where('ro.agent_id', $agentId);
                $additional->where('ao.agent_id', $agentId);
                $agentFees->where('afo.agent_id', $agentId);
                $salesFees->where('sfo.agent_id', $agentId);
                $courierFees->where('cfo.agent_id', $agentId);
            }
            $q = DB::table('orders as o')
                ->selectRaw('COUNT(*) as transaction_count')
                ->selectRaw('COALESCE(SUM(o.total_amount), 0) as gross_revenue')
                ->selectRaw('COALESCE(SUM(o.paid_amount), 0) as paid_amount')
                ->selectRaw('COALESCE(SUM(o.remaining_amount), 0) as outstanding_amount')
                ->selectSub($refunds, 'refund_amount')
                ->selectSub($additional, 'additional_payment_amount')
                ->selectSub($agentFees, 'agent_fee')
                ->selectSub($salesFees, 'sales_fee')
                ->selectSub($courierFees, 'courier_fee');
            if ($agentId !== null) {
                $q->where('o.agent_id', $agentId);
            }
        } else {
            $q = DB::table('orders as o');
            if ($agentId !== null) {
                $q->where('o.agent_id', $agentId);
            }
            match ($dataset) {
                'payment_status' => $q->select(['o.id', 'o.order_no', 'o.payment_status', 'o.paid_amount', 'o.remaining_amount']),
                'courier_deliveries' => $q->join('shipments as s', 's.order_id', '=', 'o.id')->select(['s.id', 'o.order_no', 's.courier_id', 's.tracking_number', 's.status', 's.delivered_at']),
                'sales_fees', 'courier_fees' => $q->join('commissions as c', 'c.order_id', '=', 'o.id')->where('c.beneficiary_role', $dataset === 'sales_fees' ? 'sales' : 'courier')->select(['c.id', 'o.order_no', 'c.beneficiary_user_id as beneficiary_id', 'c.amount', 'c.status', 'c.earned_at']),
                'refunds' => $q->join('order_items as i', 'i.order_id', '=', 'o.id')->join('return_items as r', 'r.order_item_id', '=', 'i.id')->select(['r.id', 'o.order_no', 'r.refund_amount', 'r.refund_status']),
                'additional_payments' => $q->join('order_additional_payments as a', 'a.order_id', '=', 'o.id')->select(['a.id', 'o.order_no', 'a.amount', 'a.method', 'a.status']),
            };
        }
        // Filter only fields available in the dataset's explicit projection.
        $result = DB::query()->fromSub($q, 'dataset');
        if (! empty($filters['status']) && in_array('status', $this->definitions()[$dataset])) {
            $result->where('status', $filters['status']);
        }

        return $result;
    }
}
