<?php

namespace App\Services\Report;

use App\Exceptions\ApiException;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Every management report (Blueprint §Reports, repeated near-verbatim across
 * the Super Admin/Agen/Admin dashboard specs). Scoped identically for every
 * caller via scopeToActor(): super_admin sees everything, agen/admin are
 * confined to their own agent_id branch — never another agent's, never
 * konsumen/sales/korsal/kurir (those never reach this service at all; see
 * ReportController's route-level role gate).
 */
class ReportService
{
    public function __construct(private readonly OrderTransactionReportService $orderTransactionReport) {}

    /**
     * $korsalColumn narrows further still for a korsal actor — without it, a
     * korsal reading a report scoped only by $agentColumn would see every
     * OTHER korsal's sales/orders in the same agen branch too. Only
     * salesReport() and transactions() (the two reports korsal can reach)
     * pass it; every other report stays agen-branch-only, as korsal never
     * reaches those routes at all.
     *
     * $filters['agent_id'], when present, is honored ONLY for super_admin —
     * every other actor stays hard-locked to their own agent_id regardless
     * of what the request sends, so this can never be used to escape a
     * branch, only to let super_admin narrow its own all-branches view down
     * to one agent.
     */
    private function scopeToActor(Builder $query, User $actor, string $agentColumn, ?string $korsalColumn = null, array $filters = []): void
    {
        if ($actor->isRole('super_admin')) {
            if (! empty($filters['agent_id'])) {
                $query->where($agentColumn, $filters['agent_id']);
            }

            return;
        }

        $query->where($agentColumn, $actor->agent_id);

        if ($korsalColumn && $actor->isRole('korsal')) {
            $query->where($korsalColumn, $actor->id);
        }
    }

    private function applyDateFilters(Builder $query, array $filters, string $column, string $fromKey = 'from', string $toKey = 'to'): void
    {
        if (! empty($filters[$fromKey])) {
            $query->whereDate($column, '>=', $filters[$fromKey]);
        }
        if (! empty($filters[$toKey])) {
            $query->whereDate($column, '<=', $filters[$toKey]);
        }
    }

    /**
     * "Jumlah transaksi lengkap per sales dan korsal berdasarkan hierarki,
     * tanggal berapa dikirim termasuk pengirimnya bisa di atur di filter,
     * satu order bisa beberapa kurir" — one row per order ITEM (not per
     * order), since each item's own shipment/courier and delivery date may
     * differ from its siblings on the very same order.
     */
    public function transactions(User $actor, array $filters): QueryBuilder
    {
        $agentId = $actor->isRole('super_admin') ? ($filters['agent_id'] ?? null) : $actor->agent_id;
        $korsalId = $actor->isRole('korsal') ? $actor->id : null;

        return $this->orderTransactionReport->query($agentId ? (int) $agentId : null, $korsalId, $filters);
    }

    /**
     * "Jumlah fee tiap sales dan korsal berdasarkan hierarki" — sales fees are
     * a real per-transaction commission (beneficiary_role='sales'); a korsal
     * has no fee of their own in this system, so their row is the hierarchy
     * roll-up of every sales beneath them (Blueprint's "berdasarkan
     * hierarki") rather than a separate ledger.
     */
    public function salesKorsalFees(User $actor, array $filters): array
    {
        $salesQuery = Commission::query()
            ->join('users', 'users.id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'sales')
            ->select(['users.id as sales_id', 'users.name as sales_name', 'users.korsal_id', 'users.agent_id'])
            ->selectRaw('SUM(commissions.amount) as total_fee, COUNT(*) as transaction_count')
            ->groupBy('users.id', 'users.name', 'users.korsal_id', 'users.agent_id');

        $this->scopeToActor($salesQuery, $actor, 'users.agent_id', null, $filters);
        $this->applyDateFilters($salesQuery, $filters, 'commissions.earned_at');

        if (! empty($filters['sales_id'])) {
            $salesQuery->where('users.id', $filters['sales_id']);
        }
        if (! empty($filters['korsal_id'])) {
            $salesQuery->where('users.korsal_id', $filters['korsal_id']);
        }

        $korsalQuery = Commission::query()
            ->join('users as sales_users', 'sales_users.id', '=', 'commissions.beneficiary_user_id')
            ->join('users as korsal_users', 'korsal_users.id', '=', 'sales_users.korsal_id')
            ->where('commissions.beneficiary_role', 'sales')
            ->select(['korsal_users.id as korsal_id', 'korsal_users.name as korsal_name', 'korsal_users.agent_id'])
            // "jumlah sales yang menjual" is a distinct-seller count, never the
            // same as transaction_count — one sales with 20 transactions must
            // still count as 1 here, not 20.
            ->selectRaw('SUM(commissions.amount) as total_fee, COUNT(*) as transaction_count, COUNT(DISTINCT sales_users.id) as active_sales_count')
            ->groupBy('korsal_users.id', 'korsal_users.name', 'korsal_users.agent_id');

        $this->scopeToActor($korsalQuery, $actor, 'korsal_users.agent_id', null, $filters);
        $this->applyDateFilters($korsalQuery, $filters, 'commissions.earned_at');

        if (! empty($filters['korsal_id'])) {
            $korsalQuery->where('korsal_users.id', $filters['korsal_id']);
        }

        return ['sales' => $salesQuery->get(), 'korsal' => $korsalQuery->get()];
    }

    /**
     * "Jumlah transaksi batal pending, batal, request refund, yang refund
     * bisa di liat kurir nya" — three distinct buckets:
     *   - cancellations: whole orders cancelled outright (status='dibatalkan').
     *   - adjustments: per-item fulfillment shortfalls that became a refund
     *     record (refund_status pending vs processed = "batal pending"/"batal").
     *   - returns: post-delivery return/refund requests, each row carrying
     *     the courier who actually delivered that item.
     */
    public function cancellationsRefunds(User $actor, array $filters): array
    {
        $cancellations = Order::query()->where('status', 'dibatalkan');
        $this->scopeToActor($cancellations, $actor, 'agent_id', null, $filters);
        $this->applyDateFilters($cancellations, $filters, 'cancelled_at');
        if (! empty($filters['sales_id'])) {
            $cancellations->where('sales_id', $filters['sales_id']);
        }
        if (! empty($filters['korsal_id'])) {
            $cancellations->where('korsal_id', $filters['korsal_id']);
        }
        $cancellations = $cancellations->with(['konsumen', 'sales', 'korsal'])
            ->orderByDesc('cancelled_at')->get();

        $adjustments = OrderItemAdjustment::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_adjustments.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->select([
                'order_item_adjustments.id', 'order_item_adjustments.quantity_reduced',
                'order_item_adjustments.refund_amount', 'order_item_adjustments.refund_status',
                'order_item_adjustments.reason', 'order_item_adjustments.created_at',
                'orders.id as order_id', 'orders.order_no', 'orders.agent_id',
                'order_items.product_name_snapshot', 'order_items.sku_snapshot as product_sku',
            ]);
        $this->scopeToActor($adjustments, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($adjustments, $filters, 'order_item_adjustments.created_at');
        if (! empty($filters['status'])) {
            $adjustments->where('order_item_adjustments.refund_status', $filters['status']);
        }
        $adjustments = $adjustments->orderByDesc('order_item_adjustments.created_at')->get();

        $returns = ReturnItem::query()
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('shipments', 'shipments.id', '=', 'order_items.shipment_id')
            ->leftJoin('couriers', 'couriers.id', '=', 'shipments.courier_id')
            ->select([
                'return_items.id', 'return_items.quantity_returned', 'return_items.refund_amount',
                'return_items.status as item_status', 'return_items.refund_status',
                'returns.reason', 'returns.created_at',
                'orders.id as order_id', 'orders.order_no', 'orders.agent_id',
                'order_items.product_name_snapshot', 'order_items.sku_snapshot as product_sku',
                'couriers.id as courier_id', 'couriers.name as courier_name',
            ]);
        $this->scopeToActor($returns, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($returns, $filters, 'returns.created_at');
        if (! empty($filters['status'])) {
            $returns->where('return_items.refund_status', $filters['status']);
        }
        if (! empty($filters['courier_id'])) {
            $returns->where('couriers.id', $filters['courier_id']);
        }
        $returns = $returns->orderByDesc('returns.created_at')->get();

        return compact('cancellations', 'adjustments', 'returns');
    }

    /** "Jumlah fee tiap kurir per kurirnya." */
    public function courierFees(User $actor, array $filters): Collection
    {
        $query = Commission::query()
            ->join('couriers', 'couriers.user_id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'courier')
            ->select(['couriers.id as courier_id', 'couriers.name as courier_name', 'couriers.agent_id'])
            ->selectRaw('SUM(commissions.amount) as total_fee, COUNT(*) as delivery_count')
            ->groupBy('couriers.id', 'couriers.name', 'couriers.agent_id');

        $this->scopeToActor($query, $actor, 'couriers.agent_id', null, $filters);
        $this->applyDateFilters($query, $filters, 'commissions.earned_at');

        if (! empty($filters['courier_id'])) {
            $query->where('couriers.id', $filters['courier_id']);
        }

        return $query->get();
    }

    /**
     * "Bisa melihat berapa total fee agen yang didapat dari semua transaksi
     * per agen" — super_admin sees every agent; an agen sees only their own
     * row. Never admin ("tidak boleh melihat fee agen" — Blueprint §Admin) —
     * enforced again here, defense in depth beyond the route's role gate.
     *
     * Also carries "jumlah sales yang menjual" per agent — a distinct count
     * of that agent's own sales-role users who earned at least one
     * commission in range, never an accumulation of order/transaction
     * counts. Computed as a second query (a sales user's agent_id, not the
     * agent's own commission rows, decides which agent a seller counts
     * toward) and merged onto each row by agent_id.
     */
    public function agentFees(User $actor, array $filters): Collection
    {
        if (! $actor->isRole('super_admin', 'agen')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $query = Commission::query()
            ->join('users', 'users.id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'agent')
            ->select(['users.id as agent_id', 'users.name as agent_name'])
            ->selectRaw('SUM(commissions.amount) as total_fee, COUNT(*) as transaction_count')
            ->groupBy('users.id', 'users.name');

        if ($actor->isRole('agen')) {
            $query->where('users.id', $actor->id);
        } elseif ($actor->isRole('super_admin') && ! empty($filters['agent_id'])) {
            $query->where('users.id', $filters['agent_id']);
        }

        $this->applyDateFilters($query, $filters, 'commissions.earned_at');

        $activeSalesQuery = Commission::query()
            ->join('users as sales_users', 'sales_users.id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'sales')
            ->select('sales_users.agent_id')
            ->selectRaw('COUNT(DISTINCT sales_users.id) as active_sales_count')
            ->groupBy('sales_users.agent_id');

        if ($actor->isRole('agen')) {
            $activeSalesQuery->where('sales_users.agent_id', $actor->id);
        } elseif ($actor->isRole('super_admin') && ! empty($filters['agent_id'])) {
            $activeSalesQuery->where('sales_users.agent_id', $filters['agent_id']);
        }

        $this->applyDateFilters($activeSalesQuery, $filters, 'commissions.earned_at');

        $activeSalesByAgent = $activeSalesQuery->get()->keyBy('agent_id');

        return $query->get()->map(function ($row) use ($activeSalesByAgent) {
            $row->active_sales_count = (int) ($activeSalesByAgent->get($row->agent_id)->active_sales_count ?? 0);

            return $row;
        });
    }

    /**
     * "Laporan status pembayaran lunas dan tidak lunas setiap agen beserta
     * hierarki" — one row per agent x payment_status combination (never
     * pre-bucketed into just "lunas/belum lunas" here — the six real
     * payment_status values carry more nuance than a boolean, and the
     * caller/frontend decides how to group them for display).
     */
    public function paymentStatus(User $actor, array $filters): Collection
    {
        $query = Order::query()
            ->leftJoin('users as agent_users', 'agent_users.id', '=', 'orders.agent_id')
            ->select(['orders.agent_id', 'agent_users.name as agent_name', 'orders.payment_status'])
            ->selectRaw('COUNT(*) as order_count, SUM(orders.total_amount) as total_amount')
            ->groupBy('orders.agent_id', 'agent_users.name', 'orders.payment_status');

        $this->scopeToActor($query, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($query, $filters, 'orders.created_at');

        if (! empty($filters['sales_id'])) {
            $query->where('orders.sales_id', $filters['sales_id']);
        }
        if (! empty($filters['korsal_id'])) {
            $query->where('orders.korsal_id', $filters['korsal_id']);
        }

        return $query->orderBy('agent_users.name')->get();
    }

    /**
     * "Keuangan: Total transaksi, Refunds semua agen" — a platform-wide (or,
     * for an agen actor, own-branch) summary: total value of paid orders,
     * and total refunded value across both refund paths (partial-item
     * adjustments and post-delivery returns). Never itself a source of
     * truth for any single order's state — purely an aggregate read.
     */
    public function financeSummary(User $actor, array $filters): array
    {
        $orders = Order::query();
        $this->scopeToActor($orders, $actor, 'agent_id', null, $filters);
        $this->applyDateFilters($orders, $filters, 'created_at');

        $totalOrders = (clone $orders)->count();
        $totalTransactions = (clone $orders)->where('payment_status', 'paid')->sum('total_amount');

        $adjustments = OrderItemAdjustment::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_adjustments.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id');
        $this->scopeToActor($adjustments, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($adjustments, $filters, 'order_item_adjustments.created_at');
        $totalAdjustmentRefunds = $adjustments->sum('order_item_adjustments.refund_amount');

        $returns = ReturnItem::query()
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id');
        $this->scopeToActor($returns, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($returns, $filters, 'returns.created_at');
        $totalReturnRefunds = $returns->sum('return_items.refund_amount');

        return [
            'total_orders' => $totalOrders,
            // "Nilai" of fully-completed transactions only (deliberately
            // excludes still-partial DP orders) — distinct from
            // total_received below, which is actual cash collected so far
            // including verified-but-not-yet-settled DP payments.
            'total_transactions' => (float) $totalTransactions,
            // Actual money collected so far, across every payment status —
            // the canonical "sudah diterima" figure (same source as
            // PaymentSummaryService/networkSummary.paid_amount: Order.
            // paid_amount, written exclusively by PaymentService).
            'total_received' => round((float) (clone $orders)->sum('paid_amount'), 2),
            'total_refunds' => (float) $totalAdjustmentRefunds + (float) $totalReturnRefunds,
            // Money still owed (unpaid / pending verification / DP partially
            // paid) and how much of that is DP-specific — the balance Keuangan
            // still has to reconcile.
            'total_outstanding' => round((float) (clone $orders)
                ->whereIn('payment_status', ['unpaid', 'pending_verification', 'partially_paid'])
                ->sum('remaining_amount'), 2),
            'total_dp_outstanding' => round((float) (clone $orders)
                ->where('payment_status', 'partially_paid')->sum('remaining_amount'), 2),
        ];
    }

    /** "Shipping: Jumlah Kurir tiap agen." */
    public function couriersPerAgent(User $actor, array $filters): Collection
    {
        $query = Courier::query()
            ->join('users as agent_users', 'agent_users.id', '=', 'couriers.agent_id')
            ->select(['couriers.agent_id', 'agent_users.name as agent_name'])
            ->selectRaw('COUNT(*) as courier_count')
            ->groupBy('couriers.agent_id', 'agent_users.name');

        $this->scopeToActor($query, $actor, 'couriers.agent_id', null, $filters);

        return $query->orderBy('agent_users.name')->get();
    }

    /**
     * "Total Konsumen yang melakukan transaksi yang berada di hierarki agen"
     * — every konsumen who has placed at least one order in range, with
     * order count and paid-total, scoped to the actor's branch. Returns an
     * unexecuted Builder so the controller can either ->paginate() (on-screen
     * table) or ->get() (full set for xlsx export) from the same query.
     */
    public function customersReport(User $actor, array $filters): Builder
    {
        $query = Order::query()
            ->join('users as konsumen_users', 'konsumen_users.id', '=', 'orders.konsumen_id')
            ->select(['konsumen_users.id as konsumen_id', 'konsumen_users.name as konsumen_name', 'konsumen_users.phone as konsumen_phone'])
            ->selectRaw("COUNT(*) as order_count, SUM(CASE WHEN orders.payment_status = 'paid' THEN orders.total_amount ELSE 0 END) as total_spent, MAX(orders.created_at) as last_order_at")
            ->groupBy('konsumen_users.id', 'konsumen_users.name', 'konsumen_users.phone');

        $this->scopeToActor($query, $actor, 'orders.agent_id', null, $filters);
        $this->applyDateFilters($query, $filters, 'orders.created_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('konsumen_users.name', 'like', "%{$search}%")->orWhere('konsumen_users.phone', 'like', "%{$search}%"));
        }

        return $query->orderByDesc('last_order_at');
    }

    /**
     * "Listing semua Korsal beserta Total sales dan Transaksi perkorsal,
     * beserta jumlah fee sales per korsal" — a real ROSTER (every korsal in
     * the branch, unlike salesKorsalFees()'s commission-driven inner join),
     * so a korsal with zero activity in range still appears with zeros.
     */
    public function korsalReport(User $actor, array $filters): Builder
    {
        $stats = Commission::query()
            ->join('users as su', 'su.id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'sales')
            ->select('su.korsal_id')
            ->selectRaw('COUNT(DISTINCT su.id) as active_sales_count, COUNT(*) as transaction_count, SUM(commissions.amount) as total_sales_fee')
            ->groupBy('su.korsal_id');
        $this->applyDateFilters($stats, $filters, 'commissions.earned_at');

        $query = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'korsal'))
            ->leftJoinSub($stats, 'stats', 'stats.korsal_id', '=', 'users.id')
            ->select(['users.id as korsal_id', 'users.name as korsal_name', 'users.phone as korsal_phone', 'users.status'])
            ->selectRaw('COALESCE(stats.active_sales_count, 0) as active_sales_count, COALESCE(stats.transaction_count, 0) as transaction_count, COALESCE(stats.total_sales_fee, 0) as total_sales_fee');

        $this->scopeToActor($query, $actor, 'users.agent_id', null, $filters);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('users.name', 'like', "%{$search}%")->orWhere('users.phone', 'like', "%{$search}%"));
        }
        if (! empty($filters['status'])) {
            $query->where('users.status', $filters['status']);
        }

        return $query->orderBy('users.name');
    }

    /**
     * "Listing semua sales yang memiliki transaksi... Termasuk fee yang
     * didapatkan sales" — a full roster (LEFT JOIN, not the commission-driven
     * inner join in salesKorsalFees()), so every sales under the branch
     * appears, active or not.
     */
    public function salesReport(User $actor, array $filters): Builder
    {
        $stats = Commission::query()
            ->where('beneficiary_role', 'sales')
            ->select('beneficiary_user_id')
            ->selectRaw('COUNT(*) as transaction_count, SUM(amount) as total_fee')
            ->groupBy('beneficiary_user_id');
        $this->applyDateFilters($stats, $filters, 'earned_at');

        // "jumlah konsumen" — distinct konsumen who ordered through this
        // sales, never a transaction-count restatement (one konsumen with 5
        // orders still counts as 1 here).
        $customerStats = Order::query()
            ->whereNotNull('sales_id')
            ->select('sales_id')
            ->selectRaw('COUNT(DISTINCT konsumen_id) as customer_count')
            ->groupBy('sales_id');
        $this->applyDateFilters($customerStats, $filters, 'created_at');

        $query = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'sales'))
            ->leftJoin('users as korsal_users', 'korsal_users.id', '=', 'users.korsal_id')
            ->leftJoinSub($stats, 'stats', 'stats.beneficiary_user_id', '=', 'users.id')
            ->leftJoinSub($customerStats, 'customer_stats', 'customer_stats.sales_id', '=', 'users.id')
            ->select(['users.id as sales_id', 'users.name as sales_name', 'users.phone as sales_phone', 'users.status', 'korsal_users.name as korsal_name'])
            ->selectRaw('COALESCE(stats.transaction_count, 0) as transaction_count, COALESCE(stats.total_fee, 0) as total_fee, COALESCE(customer_stats.customer_count, 0) as customer_count');

        $this->scopeToActor($query, $actor, 'users.agent_id', 'users.korsal_id', $filters);

        if (! empty($filters['korsal_id'])) {
            $query->where('users.korsal_id', $filters['korsal_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('users.name', 'like', "%{$search}%")->orWhere('users.phone', 'like', "%{$search}%"));
        }
        if (! empty($filters['status'])) {
            $query->where('users.status', $filters['status']);
        }

        return $query->orderBy('users.name');
    }

    /**
     * "Listing semua Kurir... berapa transaksi/order, berapa transaksi
     * return, berapa fee" — a full courier roster (every Courier row in the
     * branch) with delivery count/fee from commissions and a RETURN count
     * from the same return_items→shipments path already used in
     * cancellationsRefunds(), neither of which courierFees() carries today.
     */
    public function courierReport(User $actor, array $filters): Builder
    {
        $feeStats = Commission::query()
            ->join('couriers as c', 'c.user_id', '=', 'commissions.beneficiary_user_id')
            ->where('commissions.beneficiary_role', 'courier')
            ->select('c.id as courier_id')
            ->selectRaw('COUNT(*) as delivery_count, SUM(commissions.amount) as total_fee')
            ->groupBy('c.id');
        $this->applyDateFilters($feeStats, $filters, 'commissions.earned_at');

        $returnStats = ReturnItem::query()
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('shipments', 'shipments.id', '=', 'order_items.shipment_id')
            ->select('shipments.courier_id')
            ->selectRaw('COUNT(*) as return_count')
            ->groupBy('shipments.courier_id');
        $this->applyDateFilters($returnStats, $filters, 'returns.created_at');

        $query = Courier::query()
            ->leftJoinSub($feeStats, 'fee_stats', 'fee_stats.courier_id', '=', 'couriers.id')
            ->leftJoinSub($returnStats, 'return_stats', 'return_stats.courier_id', '=', 'couriers.id')
            ->select(['couriers.id as courier_id', 'couriers.name as courier_name', 'couriers.is_active'])
            ->selectRaw('COALESCE(fee_stats.delivery_count, 0) as delivery_count, COALESCE(fee_stats.total_fee, 0) as total_fee, COALESCE(return_stats.return_count, 0) as return_count');

        $this->scopeToActor($query, $actor, 'couriers.agent_id', null, $filters);

        if (! empty($filters['search'])) {
            $query->where('couriers.name', 'like', '%'.$filters['search'].'%');
        }

        return $query->orderBy('couriers.name');
    }

    /**
     * Dashboard-home summary widget — total orders/revenue/customers in the
     * actor's branch plus a headcount of korsal/sales/kurir under them.
     * Cheap enough (six small scoped counts) to expose to every office role.
     *
     * For korsal/sales, the order-level totals narrow further to their own
     * korsal_id/sales_id (never the whole agen branch — "Sales/Korsal hanya
     * boleh melihat data sendiri"). The korsal/sales/kurir headcounts only
     * make sense as an agen's own branch-wide rollup: a korsal or sales has
     * no such downline of its own, so those three stay 0 for them rather
     * than leaking a sibling headcount.
     */
    public function dashboardSummary(User $actor, array $filters = []): array
    {
        $orders = Order::query();
        $this->scopeToActor($orders, $actor, 'agent_id', null, $filters);
        if ($actor->isRole('korsal')) {
            $orders->where('korsal_id', $actor->id);
        } elseif ($actor->isRole('sales')) {
            $orders->where('sales_id', $actor->id);
        }

        $totalOrders = (clone $orders)->count();
        $totalRevenue = (clone $orders)->where('payment_status', 'paid')->sum('total_amount');
        $totalCustomers = (clone $orders)->distinct('konsumen_id')->count('konsumen_id');

        $countByRole = function (string $role) use ($actor, $filters) {
            if ($actor->isRole('korsal', 'sales')) {
                return 0;
            }

            $query = User::query()->whereHas('role', fn ($q) => $q->where('slug', $role));
            $this->scopeToActor($query, $actor, 'agent_id', null, $filters);

            return $query->count();
        };

        return [
            'total_orders' => $totalOrders,
            'total_revenue' => (float) $totalRevenue,
            'total_customers' => $totalCustomers,
            'total_korsal' => $countByRole('korsal'),
            'total_sales' => $countByRole('sales'),
            'total_kurir' => $countByRole('kurir'),
        ];
    }

    /**
     * "Sales hanya boleh melihat konsumen sendiri" — every konsumen this
     * sales has ever sold to, one row per konsumen, with the order count and
     * commission ('sales' beneficiary_role) fee EARNED FROM THAT KONSUMEN
     * specifically (joined via commissions.order_id, never a flat re-split
     * of the sales' total fee). Returns an unexecuted Builder so the
     * controller can paginate (its ->total() already IS the distinct
     * konsumen count — no separate query needed for that stat) or export.
     */
    public function salesCustomersReport(User $actor, array $filters): Builder
    {
        $query = Order::query()
            ->where('orders.sales_id', $actor->id)
            ->join('users as konsumen_users', 'konsumen_users.id', '=', 'orders.konsumen_id')
            ->leftJoin('commissions', function ($join) use ($actor) {
                $join->on('commissions.order_id', '=', 'orders.id')
                    ->where('commissions.beneficiary_user_id', $actor->id)
                    ->where('commissions.beneficiary_role', 'sales');
            })
            ->select(['konsumen_users.id as konsumen_id', 'konsumen_users.name as konsumen_name', 'konsumen_users.phone as konsumen_phone'])
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count, COALESCE(SUM(commissions.amount), 0) as total_fee')
            ->groupBy('konsumen_users.id', 'konsumen_users.name', 'konsumen_users.phone');

        $this->applyDateFilters($query, $filters, 'orders.created_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('konsumen_users.name', 'like', "%{$search}%")->orWhere('konsumen_users.phone', 'like', "%{$search}%"));
        }

        return $query->orderByDesc('order_count');
    }

    /** Branch-wide (not per-page) total fee this sales has earned in range — the summary stat above the salesCustomersReport table. */
    public function salesCustomersFeeTotal(User $actor, array $filters): float
    {
        $query = Commission::query()
            ->where('beneficiary_user_id', $actor->id)->where('beneficiary_role', 'sales');
        $this->applyDateFilters($query, $filters, 'earned_at');

        return (float) $query->sum('amount');
    }

    /**
     * Consolidated network summary behind the Super Admin (req 11), Agen
     * (req 12) and Keuangan dashboards: headcounts, per-agen rollup (korsal/
     * sales/sales-who-sold/transaction counts, stock, total fee), product
     * breakdown, payment-method split, paid vs outstanding money, and
     * refund/additional-payment totals. Scoped exactly like every other
     * report — super_admin sees all branches (optionally narrowed by
     * ?agent_id), everyone else is hard-locked to their own agent_id.
     *
     * @return array<string, mixed>
     */
    public function networkSummary(User $actor, array $filters): array
    {
        $orders = $this->scopedOrders($actor, $filters);
        $orderIds = (clone $orders)->select('orders.id')->pluck('id');

        $totals = [
            'order_count' => (clone $orders)->count(),
            'item_count' => (int) OrderItem::query()->whereIn('order_id', $orderIds)->sum('fulfilled_quantity'),
            'total_amount' => round((float) (clone $orders)->sum('orders.total_amount'), 2),
            // Actual money collected so far — sums Order.paid_amount across
            // every order regardless of payment_status, so a DP order's
            // already-verified partial payment counts (PaymentService is the
            // only writer of paid_amount; see PaymentSummaryService for the
            // same canonical formula used by the transaction report/detail
            // page). Summing total_amount only where payment_status='paid'
            // would silently report Rp0 collected for every still-partial DP
            // order, understating cash actually received.
            'paid_amount' => round((float) (clone $orders)->sum('orders.paid_amount'), 2),
            'outstanding_amount' => round((float) (clone $orders)
                ->whereIn('orders.payment_status', ['unpaid', 'pending_verification', 'partially_paid'])
                ->sum('orders.remaining_amount'), 2),
            'refund_total' => round((float) OrderItemAdjustment::query()
                ->join('order_items', 'order_items.id', '=', 'order_item_adjustments.order_item_id')
                ->whereIn('order_items.order_id', $orderIds)->sum('order_item_adjustments.refund_amount'), 2),
            'return_refund_total' => round((float) ReturnItem::query()
                ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
                ->whereIn('order_items.order_id', $orderIds)->sum('return_items.refund_amount'), 2),
            'additional_payment_total' => round((float) OrderAdditionalPayment::query()
                ->whereIn('order_id', $orderIds)->sum('amount'), 2),
        ];

        $scopeAgentId = $actor->isRole('super_admin') ? ($filters['agent_id'] ?? null) : $actor->agent_id;
        $countRole = function (string $role) use ($scopeAgentId) {
            $query = User::query()->whereHas('role', fn ($r) => $r->where('slug', $role));

            if ($scopeAgentId) {
                $query->where('agent_id', $scopeAgentId);
            }

            return $query->count();
        };

        $counts = [
            'korsal_count' => $countRole('korsal'),
            'sales_count' => $countRole('sales'),
            'courier_count' => $countRole('kurir'),
            // "Total Sales yang berhasil melakukan penjualan" — sales who
            // actually appear on at least one order in range.
            'sales_with_sales_count' => (int) (clone $orders)->whereNotNull('orders.sales_id')->distinct()->count('orders.sales_id'),
        ];

        $agentIds = (clone $orders)->select('orders.agent_id')->distinct()->pluck('orders.agent_id')->filter()->values()->all();
        $agentNames = User::query()->whereIn('id', $agentIds)->pluck('name', 'id');
        $countBy = fn (string $role) => User::query()
            ->whereIn('agent_id', $agentIds)
            ->whereHas('role', fn ($r) => $r->where('slug', $role))
            ->selectRaw('agent_id, COUNT(*) as c')->groupBy('agent_id')->pluck('c', 'agent_id');
        $korsalCounts = $countBy('korsal');
        $salesCounts = $countBy('sales');
        $stockByAgent = ProductStock::withoutGlobalScopes()->whereIn('agent_id', $agentIds)
            ->selectRaw('agent_id, SUM(quantity_on_hand) as q')->groupBy('agent_id')->pluck('q', 'agent_id');
        $variationStockByAgent = ProductVariationStock::withoutGlobalScopes()->whereIn('agent_id', $agentIds)
            ->selectRaw('agent_id, SUM(quantity_on_hand) as q')->groupBy('agent_id')->pluck('q', 'agent_id');
        $feeByAgent = Commission::query()
            ->join('users as beneficiaries', 'beneficiaries.id', '=', 'commissions.beneficiary_user_id')
            ->whereIn('beneficiaries.agent_id', $agentIds)
            ->selectRaw('beneficiaries.agent_id, SUM(commissions.amount) as f')
            ->groupBy('beneficiaries.agent_id')->pluck('f', 'agent_id');

        $perAgen = (clone $orders)
            ->select('orders.agent_id')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COUNT(DISTINCT orders.sales_id) as active_sales_count')
            ->selectRaw('SUM(CASE WHEN orders.sales_id IS NOT NULL THEN 1 ELSE 0 END) as sales_transaction_count')
            ->selectRaw('SUM(CASE WHEN orders.korsal_id IS NOT NULL THEN 1 ELSE 0 END) as korsal_transaction_count')
            ->selectRaw('SUM(orders.total_amount) as total_amount')
            ->selectRaw("SUM(CASE WHEN orders.payment_status = 'paid' THEN orders.total_amount ELSE 0 END) as paid_amount")
            ->selectRaw("SUM(CASE WHEN orders.payment_status IN ('unpaid','pending_verification','partially_paid') THEN orders.remaining_amount ELSE 0 END) as outstanding_amount")
            ->groupBy('orders.agent_id')
            ->get()
            ->map(fn ($row) => [
                'agent_id' => (int) $row->agent_id,
                'agent_name' => $agentNames[$row->agent_id] ?? null,
                'korsal_count' => (int) ($korsalCounts[$row->agent_id] ?? 0),
                'sales_count' => (int) ($salesCounts[$row->agent_id] ?? 0),
                'active_sales_count' => (int) $row->active_sales_count,
                'sales_transaction_count' => (int) $row->sales_transaction_count,
                'korsal_transaction_count' => (int) $row->korsal_transaction_count,
                'order_count' => (int) $row->order_count,
                'total_amount' => round((float) $row->total_amount, 2),
                'paid_amount' => round((float) $row->paid_amount, 2),
                'outstanding_amount' => round((float) $row->outstanding_amount, 2),
                'stock_quantity' => (int) ($stockByAgent[$row->agent_id] ?? 0) + (int) ($variationStockByAgent[$row->agent_id] ?? 0),
                'total_fee' => round((float) ($feeByAgent[$row->agent_id] ?? 0), 2),
            ])
            ->values()->all();

        // Grouped by name AND historical SKU so a product with variants reports
        // each variant line separately, and so no live catalog lookup is needed.
        $products = OrderItem::query()->whereIn('order_id', $orderIds)
            ->selectRaw('product_name_snapshot, sku_snapshot, SUM(fulfilled_quantity) as quantity, SUM(subtotal_snapshot) as total_amount, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('product_name_snapshot', 'sku_snapshot')->orderByDesc('quantity')->limit(50)->get();

        $paymentMethods = (clone $orders)
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'orders.payment_method_id')
            ->selectRaw('pm.code as method_code, pm.name as method_name, pm.type as method_type, COUNT(*) as order_count, SUM(orders.total_amount) as total_amount')
            ->groupBy('pm.code', 'pm.name', 'pm.type')->get();

        $paymentStatus = (clone $orders)
            ->selectRaw('orders.payment_status, COUNT(*) as order_count, SUM(orders.total_amount) as total_amount')
            ->groupBy('orders.payment_status')->get();

        return [
            ...$counts,
            'totals' => $totals,
            'per_agen' => $perAgen,
            'products' => $products,
            'payment_methods' => $paymentMethods,
            'payment_status' => $paymentStatus,
        ];
    }

    /** Orders confined to the actor's scope with every supported report filter applied (qualified column names, so grouped/joined queries stay unambiguous). */
    private function scopedOrders(User $actor, array $filters): Builder
    {
        $query = Order::query();

        if ($actor->isRole('super_admin')) {
            if (! empty($filters['agent_id'])) {
                $query->where('orders.agent_id', $filters['agent_id']);
            }
        } else {
            $query->where('orders.agent_id', $actor->agent_id);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('orders.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('orders.created_at', '<=', $filters['to']);
        }
        if (! empty($filters['sales_id'])) {
            $query->where('orders.sales_id', $filters['sales_id']);
        }
        if (! empty($filters['korsal_id'])) {
            $query->where('orders.korsal_id', $filters['korsal_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('orders.status', $filters['status']);
        }
        if (! empty($filters['payment_method'])) {
            $query->whereHas('paymentMethod', fn ($q) => $q->where('code', $filters['payment_method']));
        }
        if (! empty($filters['product_id'])) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $filters['product_id']));
        }

        return $query;
    }
}
