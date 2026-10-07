<?php

namespace App\Http\Controllers\Api\V1\Dispatch;

use App\Http\Controllers\Controller;
use App\Http\Resources\DispatchShipmentResource;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\Request;

/**
 * IMP-003 — Koordinator-Kurir dispatch workspace.
 *
 * Server-authoritative, same-agent, and the Gudang/warehouse invariant is
 * preserved: ONLY shipments of orders still in the warehouse queue are
 * dispatchable. A1-05 reconciles the dispatch unit of work with Package B's
 * one-Shipment-per-OrderItem cardinality: each UNASSIGNED standard shipment
 * of a 'diproses' order is independently dispatchable — assigning courier A
 * to Shipment A never hides Shipment B from the queue (the remaining
 * standard shipment stays visible and filterable). The separate locked
 * Gudang whole-order exclusion is NOT reused here: once a courier/executor
 * exists anywhere on an order, Gudang must no longer propose (that whole-order
 * rule lives in WarehouseOrderController + OrderPolicy::view +
 * StockRequestProposalService), while Dispatch still shows the other
 * unassigned standard shipments of that same order.
 *
 * self_sub shipments (self_delivered_by_user_id) are never dispatchable —
 * that is the owning Sales-Kurir-Sub's own flow (CourierService + whereNot
 * exclusion below). Dispatch rows are operational only: identity +
 * destination + delivery date + `paid_in_full` boolean, never amounts.
 *
 * Koordinator-Kurir is a branch dispatcher created by the Agen (same
 * agent_id). It sees ONLY its own branch's dispatchable deliveries and the
 * couriers of its branch; it cannot assign self_sub (that's a
 * Sales-Kurir-Sub's own flow) — CourierService re-enforces all of it.
 *
 * A1-01: the Koordinator can also assign a delivery to ITSELF via the same
 * canonical PATCH /shipments/{id}/courier (its Courier profile is created on
 * first self-assignment); `self_assignable` on each row drives the UI button.
 */
class DispatchController extends Controller
{
    /** The dispatchable queue: shipments of 'diproses' orders that are still unassigned. Superadmin may scope ?agent_id=. */
    public function index(Request $request)
    {
        $user = $request->user();

        // A1-18: super_admin MUST pick an explicit, validated branch — the
        // platform-oversight path. Ordinary staff stay on their own agent.
        $agentId = $user->isRole('super_admin')
            ? $this->resolveSuperAdminAgentId($request)
            : (int) $user->agent_id;

        // Order-level eligibility (must be in the warehouse queue state).
        $orderQuery = $this->eligibleOrderQuery($request, $agentId);

        $eligibleOrderIds = $orderQuery->select('id')->pluck('id');

        // Per-SHIPMENT dispatchable rows (A1-05): every UNASSIGNED STANDARD
        // shipment of an eligible order. A sibling assigned shipment to a
        // different courier does not hide the remaining unassigned ones; self_sub
        // shipments are excluded entirely (never dispatchable).
        $shipments = Shipment::query()
            ->whereIn('order_id', $eligibleOrderIds)
            ->where('delivery_mode', Shipment::DELIVERY_MODE_STANDARD)
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->whereNull('self_delivered_by_user_id');

        // UAT follow-up: the delivery-date filter is SHIPMENT-level, not order-level. The
        // order prefilter above only narrows candidate orders; without this constraint a
        // 10-Oct filter would still return that order's 15/17-Oct shipments. The shipment's
        // own items decide (canonical YYYY-MM-DD contract, never a localized label).
        if ($request->filled('delivery_date')) {
            $date = $request->string('delivery_date')->toString();
            $shipments->whereHas('orderItems', fn ($item) => $item->whereDate('requested_delivery_date', $date));
        }

        $shipments = $shipments
            ->with(['order.items.shipment.courier.user', 'order.konsumen', 'courier'])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return $this->ok(
            DispatchShipmentResource::collection($shipments)->resolve(),
            meta: ['current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage(), 'total' => $shipments->total()],
        );
    }

    /**
     * The dispatchable-order scope shared by the queue and the region options: 'diproses'
     * orders of the branch, composable with the same delivery-date, paid and region filters.
     * Both surfaces therefore enforce identical authorization/eligibility.
     */
    private function eligibleOrderQuery(Request $request, int $agentId)
    {
        $orderQuery = Order::query()
            ->where('status', 'diproses');

        if ($agentId) {
            $orderQuery->where('agent_id', $agentId);
        }

        // Shared with the Admin Order list so both surfaces interpret region / delivery-date / paid identically.
        app(\App\Services\Order\OrderFilterService::class)->applyDispatchFilters($orderQuery, $request);

        return $orderQuery;
    }

    /**
     * Cascading region options for the dispatch filters (Q–W): every value comes ONLY from
     * orders currently eligible for THIS actor's dispatch queue — the same branch/status/date/
     * paid scope as the queue itself — never from the whole Indonesian master.
     *
     * Levels compose downward: regencies need a specific province_id, districts a regency_id,
     * villages a district_id. A level without its parent returns [] so the UI can disable it.
     * Values are the canonical stored ids; labels are the canonical stored snapshots — no
     * formatted-address parsing, no external API, no numeric ids as labels. Two tiny aggregate
     * queries at most per level; no N+1.
     */
    public function regions(Request $request)
    {
        $user = $request->user();
        $agentId = $user->isRole('super_admin')
            ? $this->resolveSuperAdminAgentId($request)
            : (int) $user->agent_id;

        // A queue row is a dispatchable SHIPMENT, so constrain the options to orders that actually
        // have at least one (diproses + standard + pending + unassigned + no self-delivery owner).
        // Orders that are diproses but fully assigned contribute no visible row and must not pollute.
        $eligible = fn () => $this->eligibleOrderQuery($request, $agentId)
            ->whereHas('shipments', fn ($shipment) => $shipment
                ->where('delivery_mode', Shipment::DELIVERY_MODE_STANDARD)
                ->where('status', 'pending')
                ->whereNull('courier_id')
                ->whereNull('self_delivered_by_user_id'));

        $options = function (string $idColumn, string $nameColumn, array $parents) use ($eligible) {
            $query = $eligible();
            foreach ($parents as $column => $value) {
                $query->where($column, $value);
            }

            return $query
                ->whereNotNull($idColumn)
                ->selectRaw("{$idColumn} as id, MAX({$nameColumn}) as name")
                ->groupBy($idColumn)
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => ['id' => (string) $row->id, 'name' => (string) $row->name])
                ->filter(fn ($option) => $option['name'] !== '')
                ->values()
                ->all();
        };

        $provinceId = $request->filled('province_id') ? $request->string('province_id')->toString() : null;
        $regencyId = $request->filled('regency_id') ? $request->string('regency_id')->toString() : null;
        $districtId = $request->filled('district_id') ? $request->string('district_id')->toString() : null;

        return $this->ok([
            'provinces' => $options('province_id', 'province_snapshot', []),
            'regencies' => $provinceId ? $options('regency_id', 'regency_snapshot', ['province_id' => $provinceId]) : [],
            'districts' => $regencyId ? $options('district_id', 'district_snapshot', ['regency_id' => $regencyId]) : [],
            'villages' => $districtId ? $options('village_id', 'village_snapshot', ['district_id' => $districtId]) : [],
        ]);
    }

    /** Branch couriers the Koordinator may assign — its own agent's active couriers only. */
    public function couriers(Request $request)
    {
        $user = $request->user();
        // A1-18: super_admin passes an explicit validated branch; anything else
        // would silently resolve to agent 0 and return an empty list.
        $agentId = $user->isRole('super_admin')
            ? $this->resolveSuperAdminAgentId($request)
            : (int) $user->agent_id;

        return $this->ok(
            \App\Models\Courier::query()
                ->where('agent_id', $agentId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhereHas('user', fn ($user) => $user
                    ->where('agent_id', $agentId)->where('status', 'active')
                    ->whereHas('role', fn ($role) => $role->whereIn('slug', ['kurir', 'koordinator-kurir']))))
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    /**
     * Super Admin branch resolution for the dispatch workspace: an explicit,
     * validated ?agent_id= pointing at an existing agen. Refuses a missing or
     * unknown agent with a 422, never a 500 or an accidental agent-0 query.
     */
    private function resolveSuperAdminAgentId(Request $request): int
    {
        $agentId = $request->integer('agent_id');

        if ($agentId <= 0) {
            abort(422, 'agent_id wajib diisi untuk super_admin.');
        }

        $exists = \App\Models\User::query()
            ->where('id', $agentId)
            ->whereHas('role', fn ($q) => $q->where('slug', 'agen'))
            ->exists();

        if (! $exists) {
            abort(422, 'Agen tidak ditemukan.');
        }

        return $agentId;
    }

    /** The generic /shipments/{shipment}/courier assign route (role gate below) already does the same-branch validation via CourierService. */
}