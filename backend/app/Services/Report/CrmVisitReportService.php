<?php

namespace App\Services\Report;

use App\Exceptions\ApiException;
use App\Models\CrmVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CRM visit funnel report — SimpleV2 stage semantics taken VERBATIM from the
 * source (simpelv2.git HEAD app/Services/Dashboard/DashboardService.php:838-848):
 *   '0' = 0% (belum ada kunjungan) · 1=BS · 2=MS · 3=IP · 4=IH · 5=MK · 6=MKK
 *   · 7=Deal · 'N' = Nolak. The funnel counts CUSTOMERS at their LATEST visit
 *   status (SV2 uses MAX(CASE...) precedence per customer — PC achieves the
 *   same by taking the newest visit row per customer; checkpoint rule
 *   "latest visit supersedes older plan").
 * JML KJ = visit count in period; JML RK = customers with a future
 * plan_next_date (SV2 "Renc. Kunjungan") — follow-up remains plan_next_date,
 * FU is NOT a status (CI-07 unresolved; refusing it is locked IMP-006 behaviour).
 *
 * Scope mirrors CrmAccess read authority (never wider):
 *   sales/SKS -> own customers · korsal -> downline owners + own ·
 *   agen/admin -> branch (read-only view) · super_admin -> all (+agent filter).
 * Agent isolation is the branch column on BOTH sides; cross-Agent rows can
 * never appear.
 */
class CrmVisitReportService
{
    public const BUCKETS = ['0', 'N', '1', '2', '3', '4', '5', '6', '7'];

    public const BUCKET_LABELS = [
        '0' => '0%', '1' => 'BS', '2' => 'MS', '3' => 'IP', '4' => 'IH',
        '5' => 'MK', '6' => 'MKK', '7' => 'Deal', 'N' => 'Nolak',
    ];

    public function funnel(User $actor, array $filters): array
    {
        $customers = DB::table('crm_customers as c')
            ->join('users as owners', 'owners.id', '=', 'c.owner_user_id')
            ->select(['c.id', 'c.owner_user_id', 'c.name', 'owners.name as owner_name'])
            ->whereNull('c.archived_at');

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            $customers->where('c.owner_user_id', $actor->id);
        } elseif ($actor->isRole('korsal')) {
            $customers->where(function ($q) use ($actor) {
                $q->where('c.owner_user_id', $actor->id)->orWhere('owners.korsal_id', $actor->id);
            })->where('c.agent_id', $actor->agent_id);
        } elseif ($actor->isRole('agen', 'admin')) {
            $customers->where('c.agent_id', $actor->agent_id);
        } elseif ($actor->isRole('super_admin')) {
            if (! empty($filters['agent_id'])) {
                $customers->where('c.agent_id', (int) $filters['agent_id']);
            }
        } else {
            throw new ApiException('Role ini tidak punya cakupan laporan CRM.', 403);
        }

        $customerRows = $customers->get();
        $customerIds = $customerRows->pluck('id')->all();

        $visitQuery = DB::table('crm_visits as v')
            ->select(['v.crm_customer_id', 'v.status', 'v.visit_date', 'v.id', 'v.plan_next_date'])
            ->whereIn('v.crm_customer_id', $customerIds ?: [0]);
        if (! empty($filters['from'])) {
            $visitQuery->whereDate('v.visit_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $visitQuery->whereDate('v.visit_date', '<=', $filters['to']);
        }
        $visits = $visitQuery->orderBy('v.visit_date')->orderBy('v.id')->get();

        // Latest visit per customer (ordering asc, keep last seen).
        $latest = [];
        $visitsCount = [];
        $planned = [];
        foreach ($visits as $v) {
            $cid = $v->crm_customer_id;
            $latest[$cid] = $v->status;
            $visitsCount[$cid] = ($visitsCount[$cid] ?? 0) + 1;
            if (! empty($v->plan_next_date) && $v->plan_next_date >= now()->toDateString()) {
                $planned[$cid] = true;
            }
        }

        $perOwner = [];
        $bucketTotals = array_fill_keys(self::BUCKETS, 0);
        foreach ($customerRows as $c) {
            $bucket = $latest[$c->id] ?? '0';
            if (! isset($bucketTotals[$bucket])) {
                $bucket = '0'; // defensive: unknown stored status never vanishes
            }
            $bucketTotals[$bucket]++;
            $o = $c->owner_user_id;
            $perOwner[$o] ??= ['owner_id' => $o, 'owner_name' => $c->owner_name, 'customers' => 0, 'visits' => 0, 'rk' => 0, 'buckets' => array_fill_keys(self::BUCKETS, 0)];
            $perOwner[$o]['customers']++;
            $perOwner[$o]['buckets'][$bucket]++;
            $perOwner[$o]['visits'] += $visitsCount[$c->id] ?? 0;
            if (isset($planned[$c->id])) {
                $perOwner[$o]['rk']++;
            }
        }

        return [
            'bucket_labels' => self::BUCKET_LABELS,
            'totals' => [
                'customers' => count($customerRows),
                'visits' => array_sum($visitsCount),
                'rk' => count($planned),
                'buckets' => $bucketTotals,
                'conversion_percent' => count($customerRows)
                    ? round($bucketTotals['7'] / count($customerRows) * 100, 1)
                    : 0,
            ],
            'rows' => collect($perOwner)->sortByDesc('customers')->values(),
        ];
    }
}
