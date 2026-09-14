<?php

namespace App\Http\Controllers\Api\V1\Fee;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommissionResource;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Fee reporting. Visibility mirrors CommissionPolicy exactly (this model has
 * no agent_id column of its own — see Commission migration — so unlike
 * Order/ProductStock there is no global scope; every query here is scoped
 * explicitly). Every branch below ALSO restricts which beneficiary_role
 * values are ever returned, not just whose rows — a role must never learn
 * a fee type it isn't allowed to see even exists, not just have it zeroed.
 */
class CommissionController extends Controller
{
    /** Which beneficiary_role values (agent/sales/courier) a given actor may ever see. */
    private function allowedBeneficiaryRoles(User $user): array
    {
        return match (true) {
            $user->isRole('super_admin', 'agen') => ['agent', 'sales', 'courier'],
            // Admin/Keuangan: sales + courier fee — never agent fee, full
            // stop. An agen who directly referred a konsumen earns a
            // separate 'sales'-role commission for that (see
            // OrderService::recordCommission), so this never needs an
            // agent-fee exception to let them verify referral payouts.
            $user->isRole('admin', 'keuangan') => ['sales', 'courier'],
            $user->isRole('sales') => ['sales'],
            $user->isRole('kurir') => ['courier'],
            $user->isRole('korsal') => ['sales'],
            default => [],
        };
    }

    private function scopeToActor(Builder $query, Request $request, User $user): void
    {
        if ($user->isRole('super_admin')) {
            if ($request->filled('agent_id')) {
                $query->whereHas('beneficiary', fn ($q) => $q->where('agent_id', $request->integer('agent_id')));
            }
        } elseif ($user->isRole('agen', 'admin', 'keuangan')) {
            $query->whereHas('beneficiary', fn ($q) => $q->where('agent_id', $user->agent_id));
        } elseif ($user->isRole('korsal')) {
            // Sales fee earned by sales reps under this korsal's own network,
            // PLUS the korsal's own commission when they were themselves a
            // konsumen's direct referrer (beneficiary_user_id = the korsal).
            $query->where(function ($q) use ($user) {
                $q->where('beneficiary_user_id', $user->id)
                    ->orWhereHas('beneficiary', fn ($qq) => $qq->where('korsal_id', $user->id));
            });
        } else {
            $query->where('beneficiary_user_id', $user->id);
        }

        $query->whereIn('beneficiary_role', $this->allowedBeneficiaryRoles($user));
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Commission::query()->with('beneficiary');
        $this->scopeToActor($query, $request, $user);

        if ($request->filled('beneficiary_role')) {
            $query->where('beneficiary_role', $request->string('beneficiary_role'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('earned_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('earned_at', '<=', $request->date('to'));
        }

        $commissions = $query->latest('earned_at')->paginate($request->integer('per_page', 15));

        return $this->ok(CommissionResource::collection($commissions)->resolve(), meta: [
            'current_page' => $commissions->currentPage(),
            'last_page' => $commissions->lastPage(),
            'total' => $commissions->total(),
        ]);
    }

    /** Aggregated fee report — same scoping as index(), just summed instead of listed. */
    public function summary(Request $request)
    {
        $user = $request->user();

        $query = Commission::query();
        $this->scopeToActor($query, $request, $user);

        if ($request->filled('from')) {
            $query->whereDate('earned_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('earned_at', '<=', $request->date('to'));
        }

        $rows = (clone $query)
            ->selectRaw('beneficiary_role, status, SUM(amount) as total_amount, COUNT(*) as count')
            ->groupBy('beneficiary_role', 'status')
            ->get();

        $allowed = $this->allowedBeneficiaryRoles($user);
        $totals = [];
        if (in_array('agent', $allowed, true)) {
            $totals['total_agent_fee'] = (float) (clone $query)->where('beneficiary_role', 'agent')->sum('amount');
        }
        if (in_array('sales', $allowed, true)) {
            $totals['total_sales_fee'] = (float) (clone $query)->where('beneficiary_role', 'sales')->sum('amount');
        }
        if (in_array('courier', $allowed, true)) {
            $totals['total_courier_fee'] = (float) (clone $query)->where('beneficiary_role', 'courier')->sum('amount');
        }

        return $this->ok(['breakdown' => $rows, ...$totals]);
    }
}
