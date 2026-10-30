<?php

namespace App\Services\Report;

use App\Models\Commission;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Stock\SellableStockService;
use App\Services\Crm\CrmAccess;
use App\Services\Order\ReturnService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner-approved dashboard KPI contracts (Phase 2). Every figure is derived from an existing canonical source and is
 * returned only to the role it is defined for; a key that does not apply to the actor is simply absent.
 *
 *  - agen             `own_fee`          fee personally earned by the Agen (commissions.beneficiary_user_id = actor), NOT the branch pool.
 *  - korsal           `team_commissions` per-recipient Sales fee breakdown (downline Sales/SKS + the Korsal's OWN Sales fee). There is no Korsal-role fee.
 *  - sales/SKS/korsal `deals`            unique attributed orders (self-purchase included, paid or not) — separate from `commission`.
 *  - sales/SKS        `commission`       own Sales fee from the ledger.
 *  - gudang           `available_stock`  sellable physical minus reserved (SellableStockService projection), branch scope.
 *  - kurir            `tasks_today`      assigned standard shipments with an item due today (WIB), distinct shipments, plus GAP-A `return_pickups` scheduled for today.
 *  - sales/SKS/korsal  `follow_ups`       GAP-B "Perlu Tindakan": CRM follow-ups due today + overdue (latest visit plan_next_date, CrmAccess read scope).
 *
 * Ledger semantics: `total` = pending + approved + paid (earned, not reversed); `reversed` is reported but never added.
 */
class DashboardKpiService
{
    public const STATUSES = ['pending', 'approved', 'paid', 'reversed'];

    public const TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly ReportService $reports,
        private readonly SellableStockService $sellableStock,
        private readonly ReturnService $returns,
        private readonly CrmAccess $crmAccess,
    ) {}

    /** @return array<string, mixed> */
    public function forActor(User $actor, array $filters = []): array
    {
        $out = [];

        if ($actor->isRole('agen')) {
            $out['own_fee'] = $this->ownFee($actor, $filters);
        }
        if ($actor->isRole('korsal')) {
            $out['team_commissions'] = $this->teamCommissions($actor, $filters);
        }
        if ($actor->isRole('sales', 'sales-kurir-sub', 'korsal')) {
            $out['deals'] = $this->deals($actor, $filters);
        }
        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            $out['commission'] = $this->ledgerFor($actor->id, 'sales', $filters);
        }
        if ($actor->isRole('sales', 'sales-kurir-sub', 'korsal')) {
            $out['follow_ups'] = $this->crmFollowUps($actor);
        }
        if ($actor->isRole('gudang')) {
            $out['available_stock'] = $this->availableStock($actor);
        }
        if ($actor->isRole('kurir')) {
            $out['tasks_today'] = $this->courierTasksToday($actor);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function ownFee(User $actor, array $filters): array
    {
        $ledger = $this->ledgerFor($actor->id, null, $filters);

        $byRole = Commission::query()
            ->where('beneficiary_user_id', $actor->id)
            ->where('status', '!=', 'reversed')
            ->tap(fn ($q) => $this->period($q, $filters))
            ->selectRaw('beneficiary_role, SUM(amount) as total')
            ->groupBy('beneficiary_role')
            ->pluck('total', 'beneficiary_role')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        return $ledger + ['by_role' => $byRole];
    }

    /** @return array{total: float, pending: float, approved: float, paid: float, reversed: float, count: int} */
    private function ledgerFor(int $userId, ?string $beneficiaryRole, array $filters): array
    {
        $rows = Commission::query()
            ->where('beneficiary_user_id', $userId)
            ->when($beneficiaryRole !== null, fn ($q) => $q->where('beneficiary_role', $beneficiaryRole))
            ->tap(fn ($q) => $this->period($q, $filters))
            ->selectRaw('status, SUM(amount) as total, COUNT(*) as n')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $out = [];
        $count = 0;
        foreach (self::STATUSES as $status) {
            $out[$status] = round((float) ($rows[$status]->total ?? 0), 2);
            if ($status !== 'reversed') {
                $count += (int) ($rows[$status]->n ?? 0);
            }
        }
        $total = round($out['pending'] + $out['approved'] + $out['paid'], 2);

        return ['total' => $total] + $out + ['count' => $count];
    }

    private function period($query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate('earned_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('earned_at', '<=', $filters['to']);
        }
    }

    /**
     * One row per authorized recipient (the Korsal itself + downline Sales/SKS of the SAME Agent), straight from the
     * ledger rows whose beneficiary is that user and whose role is 'sales'. A ledger row has exactly one beneficiary, so
     * it appears in at most one member row. Order count/value are reported SEPARATELY (network, not commission).
     *
     * @return array{members: list<array<string, mixed>>, totals: array<string, float>}
     */
    private function teamCommissions(User $actor, array $filters): array
    {
        $members = User::query()
            ->with('role:id,slug')
            ->where('agent_id', $actor->agent_id)
            ->where(function ($q) use ($actor) {
                $q->where('id', $actor->id)
                    ->orWhere(fn ($qq) => $qq->where('korsal_id', $actor->id)
                        ->whereHas('role', fn ($r) => $r->whereIn('slug', Role::sellerSlugs())));
            })
            ->orderByRaw('id = ? desc', [$actor->id])->orderBy('name')
            ->get(['id', 'name', 'role_id', 'status']);

        $ids = $members->pluck('id')->all();

        $ledger = Commission::query()
            ->whereIn('beneficiary_user_id', $ids)
            ->where('beneficiary_role', 'sales')
            ->tap(fn ($q) => $this->period($q, $filters))
            ->selectRaw('beneficiary_user_id, status, SUM(amount) as total, COUNT(*) as n')
            ->groupBy('beneficiary_user_id', 'status')
            ->get()
            ->groupBy('beneficiary_user_id');

        $orders = DB::query()->fromSub($this->reports->attributedOrders($filters), 'att')
            ->whereIn('att.referrer_id', $ids)
            ->selectRaw('att.referrer_id, COUNT(DISTINCT att.order_id) as orders, COALESCE(SUM(att.total_amount), 0) as value')
            ->groupBy('att.referrer_id')
            ->get()
            ->keyBy('referrer_id');

        $totals = ['total' => 0.0, 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'reversed' => 0.0];
        $rows = [];
        foreach ($members as $m) {
            $byStatus = ($ledger[$m->id] ?? collect())->keyBy('status');
            $row = [
                'user_id' => $m->id,
                'name' => $m->name,
                'role' => $m->role?->slug,
                'is_self' => $m->id === $actor->id,
                'account_status' => $m->status,
            ];
            foreach (self::STATUSES as $s) {
                $row[$s] = round((float) ($byStatus[$s]->total ?? 0), 2);
            }
            $row['total'] = round($row['pending'] + $row['approved'] + $row['paid'], 2);
            $row['order_count'] = (int) ($orders[$m->id]->orders ?? 0);
            $row['order_value'] = round((float) ($orders[$m->id]->value ?? 0), 2);
            foreach (array_keys($totals) as $k) {
                $totals[$k] = round($totals[$k] + $row[$k], 2);
            }
            $rows[] = $row;
        }

        return ['members' => $rows, 'totals' => $totals];
    }

    /** @return array{count: int, value: float} */
    private function deals(User $actor, array $filters): array
    {
        $q = DB::query()->fromSub($this->reports->attributedOrders($filters), 'att');

        if ($actor->isRole('korsal')) {
            // Team deals = the Korsal's own attributed orders + every downline seller's (same key as Laporan Korsal).
            $teamIds = User::query()->where('agent_id', $actor->agent_id)->where('korsal_id', $actor->id)->pluck('id')->push($actor->id)->all();
            $q->whereIn('att.referrer_id', $teamIds);
        } else {
            $q->where('att.referrer_id', $actor->id);
        }

        $row = $q->selectRaw('COUNT(DISTINCT att.order_id) as n, COALESCE(SUM(att.total_amount), 0) as v')->first();

        return ['count' => (int) $row->n, 'value' => round((float) $row->v, 2)];
    }

    /**
     * GAP-B (Owner 2026-10-30): "Perlu Tindakan" = CRM follow-up. Due/overdue counts for the
     * actor's OWN (Sales/SKS) CRM customers only — or, for a Korsal, own + authorized downline,
     * through the SAME CrmAccess::scopeVisible rule as every CRM read. Source = the customer's
     * LATEST visit plan_next_date (an older plan is automatically superseded by a newer visit, so
     * an already-handled follow-up is never counted again). `due_today` = plan is today (WIB),
     * `overdue` = plan before today; both still require action. No new task entity is invented.
     *
     * @return array{due_today: int, overdue: int}
     */
    private function crmFollowUps(User $actor): array
    {
        $latest = CrmVisit::query()
            ->select('crm_customer_id', DB::raw('MAX(id) as id'))
            ->groupBy('crm_customer_id');

        $base = CrmVisit::query()
            ->joinSub($latest, 'latest', fn ($j) => $j->on('latest.id', '=', 'crm_visits.id'))
            ->whereIn('crm_visits.crm_customer_id', $this->crmAccess->scopeVisible(CrmCustomer::query()->whereNull('archived_at'), $actor)->select('crm_customers.id'))
            ->whereNotNull('crm_visits.plan_next_date');

        $today = now(self::TIMEZONE)->toDateString();

        return [
            'due_today' => (clone $base)->whereDate('crm_visits.plan_next_date', '=', $today)->count(),
            'overdue' => (clone $base)->whereDate('crm_visits.plan_next_date', '<', $today)->count(),
        ];
    }

    /** @return array{units: int, targets: int} */
    private function availableStock(User $actor): array
    {
        if (! $actor->agent_id) {
            return ['units' => 0, 'targets' => 0];
        }

        $row = $this->sellableStock->projection([(int) $actor->agent_id])
            ->selectRaw('COALESCE(SUM(quantity), 0) as units, COUNT(*) as targets')
            ->first();

        return ['units' => (int) $row->units, 'targets' => (int) $row->targets];
    }

    /**
     * Delivery tasks: assigned standard shipments with an item due today (WIB), distinct shipments.
     * Return pickups (GAP-A, Owner-approved): return items scheduled for TODAY (WIB) that this kurir
     * still has to act on — shared ReturnService::scheduledPickupsToday derivation (own claim or
     * unclaimed in the same branch), item grain (each item is its own pickup claim).
     *
     * @return array{date: string, deliveries: int, return_pickups: int}
     */
    private function courierTasksToday(User $actor): array
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();
        $courierId = $actor->courierProfile?->id;

        $count = $courierId === null ? 0 : OrderItem::query()
            ->whereDate('requested_delivery_date', $today)
            ->whereIn('status', ['diproses', 'dikirim'])
            ->whereHas('shipment', fn ($s) => $s->where('courier_id', $courierId)->where('delivery_mode', 'standard'))
            ->whereHas('order', fn ($o) => $o->where('agent_id', $actor->agent_id))
            ->distinct('shipment_id')->count('shipment_id');

        return ['date' => $today, 'deliveries' => $count, 'return_pickups' => $this->returns->scheduledPickupsToday($actor)];
    }
}
