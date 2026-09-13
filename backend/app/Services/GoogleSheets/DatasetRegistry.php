<?php

namespace App\Services\GoogleSheets;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DatasetRegistry
{
    /** Public keys never become arbitrary SQL identifiers. */
    public function definitions(): array
    {
        return [
            'products' => ['id', 'sku', 'product_name', 'price', 'status'],
            'stock' => ['id', 'sku', 'product_name', 'quantity', 'reserved_quantity'],
            'transactions' => ['id', 'order_no', 'total_amount', 'status', 'payment_status', 'created_at'],
            'transaction_items' => ['id', 'order_no', 'sku', 'product_name', 'quantity', 'subtotal'],
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
        ];
    }

    public function query(string $dataset, ?int $agentId, array $filters = []): Builder
    {
        if (! isset($this->definitions()[$dataset])) {
            throw ValidationException::withMessages(['dataset' => 'Dataset tidak diizinkan.']);
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
        } else {
            $q = DB::table('orders as o');
            if ($agentId !== null) {
                $q->where('o.agent_id', $agentId);
            }
            match ($dataset) {
                'transactions' => $q->select(['o.id', 'o.order_no', 'o.total_amount', 'o.status', 'o.payment_status', 'o.created_at']),
                'payment_status' => $q->select(['o.id', 'o.order_no', 'o.payment_status', 'o.paid_amount', 'o.remaining_amount']),
                'transaction_items' => $q->join('order_items as i', 'i.order_id', '=', 'o.id')->select(['i.id', 'o.order_no', 'i.sku_snapshot as sku', 'i.product_name_snapshot as product_name', 'i.original_quantity as quantity', 'i.subtotal_snapshot as subtotal']),
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
