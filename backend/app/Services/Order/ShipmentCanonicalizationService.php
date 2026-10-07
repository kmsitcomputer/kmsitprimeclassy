<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCKED GROUPING RULE (Human UAT-005): within ONE order, compatible delivery items for the SAME
 * requested delivery date share ONE canonical Shipment.
 *
 * Two entry points, one rule:
 *   - {@see OrderService::createOrder} creates shipments grouped this way from the start;
 *   - this class reconciles LEGACY/ALREADY-CREATED duplicates produced before that rule existed
 *     (or by any other path that still splits one date across several shipment ids).
 *
 * It is a domain operation, deliberately NOT a visual grouping: courier assignment, delivery
 * progression and the thermal receipt all operate on the real canonical Shipment row, so the
 * consolidation is what actually collapses N cards into one.
 *
 * SAFETY (conservative by construction — a candidate that fails ANY check is reported, never
 * partially applied):
 *   - same order only;
 *   - same requested delivery date only (NULL is its own bucket, never mixed with a real date);
 *   - compatible delivery semantics: identical `delivery_mode` and, for self_sub, the SAME
 *     self-delivering owner. A self_sub shipment never merges into a standard one, and two
 *     different Sub owners never merge;
 *   - every candidate is still mutable: `status = pending`, no `shipped_at`/`delivered_at`;
 *   - no courier assignment (`courier_id` null) and no delivery proof (`proof_media_id` null);
 *   - no tracking number, no delivery verification rows, no delivery activity history;
 *   - canonical = the OLDEST id in the group (stable, keeps the shipping fee snapshot);
 *
 * DELETION SCOPE (deliberately narrow): only shipments EMPTIED BY THIS CONSOLIDATION are removed,
 * and each is re-verified under lock. A shipment that already holds no items was never part of a
 * duplicate same-date group (it carries no date identity to reason about) and is therefore left
 * alone — sweeping up unrelated empty rows would be a different, broader cleanup operation.
 * Nothing with execution, receipt or delivery state is ever deleted.
 */
class ShipmentCanonicalizationService
{
    /**
     * Merges duplicate same-date shipments of one order.
     *
     * @return array{
     *     order_id:int, order_no:?string, dry_run:bool, canonical_shipment_id:?int,
     *     merged_shipment_ids:array<int,int>, moved_items:array<int,array{item_id:int,from:int,to:int}>,
     *     deleted_empty_shipments:array<int,int>, conflicts:array<int,array{shipment_id:int,reason:string}>
     * }
     */
    public function reconcileOrder(Order $order, bool $dryRun = false): array
    {
        $report = [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'dry_run' => $dryRun,
            'canonical_shipment_id' => null,
            'merged_shipment_ids' => [],
            'moved_items' => [],
            'deleted_empty_shipments' => [],
            'conflicts' => [],
        ];

        if ($dryRun) {
            return $this->planFor($order, $report);
        }

        DB::transaction(function () use ($order, &$report) {
            // Canonical Order-first lock order (AGENTS.md §11): Order → Shipment → items.
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $report = $this->planFor($order, $report);

            // Any conflict anywhere means we do NOT partially mutate the order — report and stop.
            if ($report['conflicts'] !== []) {
                throw new RuntimeException(
                    'Shipment consolidation refused for order '.$order->id.': '.$this->describeConflicts($report['conflicts'])
                );
            }

            $this->applyPlan($report);

            ActivityLogger::log(null, $order, 'shipment.canonicalized', null, [
                'order_id' => $order->id,
                'canonical_shipment_id' => $report['canonical_shipment_id'],
                'merged_shipment_ids' => $report['merged_shipment_ids'],
                'moved_item_count' => count($report['moved_items']),
                'deleted_empty_shipment_ids' => $report['deleted_empty_shipments'],
            ]);
        });

        return $report;
    }

    /**
     * Writes a conflict-free plan inside the CALLER's transaction: moves the planned items onto
     * their canonical shipments and removes the emptied redundant rows (each re-verified under
     * lock). Used by {@see reconcileOrder} and by the in-transaction reschedule regrouping in
     * OrderFulfillmentService, so both paths share one write core.
     */
    public function applyPlan(array $report): void
    {
        foreach ($report['moved_items'] as $move) {
            OrderItem::query()->whereKey($move['item_id'])
                ->update(['shipment_id' => $move['to']]);
        }

        // Only truly empty, never-dispatched redundant rows are removed.
        foreach ($report['deleted_empty_shipments'] as $shipmentId) {
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->firstOrFail();

            // Re-verify under the lock: never delete on a stale plan.
            if ($shipment->status !== 'pending'
                || $shipment->courier_id !== null
                || $shipment->proof_media_id !== null
                || OrderItem::query()->where('shipment_id', $shipment->id)->exists()) {
                throw new RuntimeException('Refusing to delete shipment '.$shipmentId.': it is no longer safe to remove.');
            }

            $shipment->delete();
        }
    }

    /**
     * Merges ONE already-verified compatible group inside the caller's transaction: moves every
     * item onto $canonical and removes the shipments this empties. All $shipments must already
     * have passed {@see mergeBlocker}; a second check is NOT performed here because the caller
     * holds the canonical Order lock and the rows it read. Returns the ids removed.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int>
     */
    public function mergeGroupInto(Collection $shipments, Shipment $canonical): array
    {
        $removed = [];

        $moved = OrderItem::query()->where('order_id', $canonical->order_id)
            ->whereIn('shipment_id', $shipments->pluck('id')->all())
            ->where('shipment_id', '!=', $canonical->id)
            ->update(['shipment_id' => $canonical->id]);

        foreach ($shipments as $shipment) {
            if ($shipment->id === $canonical->id) {
                continue;
            }

            $fresh = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'pending'
                || $fresh->courier_id !== null
                || $fresh->proof_media_id !== null
                || OrderItem::query()->where('shipment_id', $fresh->id)->exists()) {
                throw new RuntimeException('Refusing to delete shipment '.$fresh->id.': it is no longer safe to remove.');
            }

            $fresh->delete();
            $removed[] = $fresh->id;
        }

        return $removed;
    }

    /**
     * Read-only plan. Reused by the dry run and by the mutating path so the two can never disagree.
     */
    private function planFor(Order $order, array $report): array
    {
        $items = OrderItem::query()->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'shipment_id', 'requested_delivery_date']);

        $shipmentIds = $items->pluck('shipment_id')->filter()->unique()->values();

        if ($shipmentIds->isEmpty()) {
            return $report;
        }

        $shipments = Shipment::query()->where('order_id', $order->id)
            ->whereIn('id', $shipmentIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // Group items by (date, mode, owner). Compatibility is decided by the shipment's own
        // semantics, so two items can never be merged across a standard/self_sub boundary.
        $groups = [];
        foreach ($items as $item) {
            $shipment = $item->shipment_id ? $shipments->get($item->shipment_id) : null;
            $key = implode('|', [
                $item->requested_delivery_date?->toDateString() ?? '',
                $shipment?->delivery_mode ?? '',
                $shipment?->delivery_mode === Shipment::DELIVERY_MODE_SELF_SUB
                    ? (string) ($shipment->self_delivered_by_user_id ?? '')
                    : '',
            ]);
            $groups[$key][] = $item;
        }

        // $itemsInGroup is a plain array of OrderItem — wrap it so the plan reads like a collection.
        foreach (array_map(fn (array $group) => new Collection($group), $groups) as $itemsInGroup) {
            $groupShipmentIds = $itemsInGroup->pluck('shipment_id')->filter()->unique()->values();

            if ($groupShipmentIds->count() < 2) {
                continue;
            }

            $groupShipments = $shipments->only($groupShipmentIds->all())->values();

            // Every shipment in the group must be safe; a single unsafe candidate aborts the group.
            $unsafe = [];
            foreach ($groupShipments as $shipment) {
                $reason = $this->mergeBlocker($shipment);
                if ($reason !== null) {
                    $unsafe[] = ['shipment_id' => $shipment->id, 'reason' => $reason];
                }
            }

            if ($unsafe !== []) {
                foreach ($unsafe as $conflict) {
                    $report['conflicts'][] = $conflict;
                }

                continue;
            }

            $canonical = $groupShipments->sortBy('id')->first();
            $report['canonical_shipment_id'] ??= $canonical->id;

            foreach ($groupShipments as $shipment) {
                if ($shipment->id !== $canonical->id) {
                    $report['merged_shipment_ids'][$shipment->id] = $canonical->id;
                }
            }

            foreach ($itemsInGroup as $item) {
                if ($item->shipment_id !== $canonical->id) {
                    $report['moved_items'][] = [
                        'item_id' => $item->id,
                        'from' => $item->shipment_id,
                        'to' => $canonical->id,
                    ];
                }
            }

            // After the move, every non-canonical shipment in this group holds no items: it is a
            // truly empty, never-dispatched row and may be removed.
            foreach ($groupShipments as $shipment) {
                if ($shipment->id !== $canonical->id) {
                    $report['deleted_empty_shipments'][] = $shipment->id;
                }
            }
        }

        return $report;
    }

    /**
     * Returns null when this shipment may be merged, otherwise the blocking reason. Public so the
     * in-transaction reschedule path applies the exact same eligibility rule.
     */
    public function mergeBlocker(Shipment $shipment): ?string
    {
        if ($shipment->status !== 'pending') {
            return 'status is '.$shipment->status.' (only a pending shipment is mutable)';
        }
        if ($shipment->courier_id !== null) {
            return 'a courier/executor is already assigned';
        }
        if ($shipment->self_delivered_by_user_id !== null
            && $shipment->delivery_mode !== Shipment::DELIVERY_MODE_SELF_SUB) {
            return 'a self-delivery owner is set on a non-self_sub shipment';
        }
        if ($shipment->proof_media_id !== null) {
            return 'a delivery proof already exists';
        }
        if ($shipment->tracking_number !== null) {
            return 'a tracking/resi number already exists';
        }
        if ($shipment->shipped_at !== null || $shipment->delivered_at !== null) {
            return 'delivery progression already happened';
        }
        if (\App\Models\DeliveryVerification::query()->where('shipment_id', $shipment->id)->exists()) {
            return 'a delivery verification record exists';
        }

        return null;
    }

    private function describeConflicts(array $conflicts): string
    {
        return collect($conflicts)->map(fn ($c) => 'shipment '.$c['shipment_id'].' — '.$c['reason'])->implode('; ');
    }
}