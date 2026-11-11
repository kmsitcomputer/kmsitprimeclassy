<?php

namespace App\Services\Order;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\User;

/**
 * "PEMENUHAN KURANG" read model for the Admin Order page.
 *
 * Nothing here is stored or estimated by the client: every figure is derived from the authoritative demand
 * lines (`stock_request_items`: requested / fulfilled-accepted / remaining) and the audit trail rows Gudang
 * wrote when it recorded a shortage (`stock_request.fulfillment_shortage`, with the reason). The Admin's
 * decision on the remainder is read back from the existing audit events of the existing operations
 * (`order_item.delivery_rescheduled`, `order_item.undelivered_cancelled`), so the view can never disagree
 * with what those operations actually did.
 *
 * A line is part of the shortage view only when Gudang reported it short. A line that merely still awaits a
 * later batch (never reported short) is NOT a shortage and never raises the alert.
 */
class FulfillmentShortageService
{
    /** Only office roles that may decide on the remainder see the block. */
    public function visibleTo(?User $user): bool
    {
        return $user !== null && $user->isRole('super_admin', 'agen', 'admin');
    }

    /**
     * @return array<string, mixed>|null null when the order never had a Gudang-reported shortage
     */
    public function forOrder(Order $order): ?array
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->first();
        if (! $request) {
            return null;
        }

        $items = StockRequestItem::query()->where('stock_request_id', $request->id)
            ->with(['product:id,name,sku', 'variation'])->orderBy('id')->get();
        if ($items->isEmpty()) {
            return null;
        }

        $reports = ActivityLog::query()
            ->where('subject_type', StockRequestItem::class)->whereIn('subject_id', $items->pluck('id'))
            ->where('event', 'stock_request.fulfillment_shortage')->with('causer:id,name')->orderBy('id')->get()
            ->groupBy('subject_id')->map(fn ($logs) => $logs->last());
        if ($reports->isEmpty()) {
            return null;
        }

        // Quantity Gudang already handed over that the Koordinator has not confirmed yet is not "short" any more.
        $awaiting = \App\Models\StockRequestProposalItem::query()
            ->whereHas('proposal', fn ($q) => $q->withoutGlobalScopes()->where('stock_request_id', $request->id)->where('status', 'pending'))
            ->whereIn('stock_request_item_id', $items->pluck('id'))
            ->selectRaw('stock_request_item_id, SUM(quantity) AS qty')->groupBy('stock_request_item_id')->pluck('qty', 'stock_request_item_id');

        $decisions = ActivityLog::query()
            ->where('subject_type', \App\Models\OrderItem::class)
            ->whereIn('subject_id', \App\Models\OrderItem::query()->where('order_id', $order->id)->pluck('id'))
            ->whereIn('event', ['order_item.delivery_rescheduled', 'order_item.undelivered_cancelled'])
            ->with('causer:id,name')->orderBy('id')->get()->groupBy('subject_id');

        // A carved batch (Koordinator split) or a rescheduled remainder is a NEW order line that carries part of the
        // demand; the shortage Gudang reported belongs to the whole original line, so group by its root line.
        $orderItems = \App\Models\OrderItem::query()->where('order_id', $order->id)->get(['id', 'split_from_order_item_id', 'original_quantity'])->keyBy('id');
        $rootOf = fn (?int $id): ?int => $this->rootOf($orderItems, $id);
        $groups = $items->groupBy(fn (StockRequestItem $i) => $i->order_item_id ? ($rootOf($i->order_item_id) ?? $i->order_item_id) : 'd'.$i->id);

        $rows = [];
        foreach ($groups as $groupKey => $lines) {
            $report = $lines->map(fn ($l) => $reports->get($l->id))->filter()->sortBy('id')->last();
            if (! $report) {
                continue;
            }
            $memberIds = $orderItems->keys()->filter(fn ($id) => (string) ($rootOf($id) ?? $id) === (string) $groupKey)->values();
            $shorts = $lines->mapWithKeys(fn ($l) => [$l->id => max(0, (int) $l->remaining_qty - (int) ($awaiting[$l->id] ?? 0))]);
            $short = (int) $shorts->sum();
            $remainder = $lines->sortByDesc(fn ($l) => $shorts[$l->id])->first();
            $decision = $memberIds->flatMap(fn ($id) => $decisions->get($id) ?? collect())
                ->filter(fn (ActivityLog $log) => $log->created_at >= $report->created_at)->sortBy('id')->last();
            $head = $lines->first();

            $status = match (true) {
                $short > 0 && $decision?->event === 'order_item.delivery_rescheduled' => 'rescheduled',
                $short > 0 => 'pending_decision',
                $decision?->event === 'order_item.undelivered_cancelled' => 'cancelled',
                $decision?->event === 'order_item.delivery_rescheduled' => 'rescheduled',
                default => 'resolved',
            };
            $rows[] = [
                'stock_request_item_id' => $remainder->id,
                // The line the Admin acts on (existing reschedule / cancel-undelivered endpoints): the one holding the remainder.
                'order_item_id' => $remainder->order_item_id,
                'product_name' => $head->product?->name,
                'variation_label' => $head->variation?->label(),
                'sku' => $head->sku_snapshot ?? $head->variation?->sku ?? $head->product?->sku,
                'unit' => null, // the product catalogue has no unit-of-measure field; never invented here
                'ordered_qty' => (int) ($memberIds->isEmpty() ? $lines->sum('requested_qty') : $memberIds->sum(fn ($id) => (int) $orderItems[$id]->original_quantity)),
                'handed_over_qty' => (int) $lines->sum('fulfilled_qty'),
                'awaiting_receipt_qty' => (int) $lines->sum(fn ($l) => (int) ($awaiting[$l->id] ?? 0)),
                'short_qty' => $short,
                'reason' => $report->description,
                'reported_at' => $report->created_at?->toIso8601String(),
                'reported_by' => $report->causer ? ['id' => $report->causer->id, 'name' => $report->causer->name] : null,
                'status' => $status,
                'decision' => $decision ? [
                    'action' => $decision->event === 'order_item.undelivered_cancelled' ? 'cancel_remainder' : 'reschedule',
                    'reason' => $decision->description,
                    'quantity' => $decision->properties['quantity_cancelled'] ?? null,
                    'date' => $decision->properties['to'] ?? null,
                    'by' => $decision->causer ? ['id' => $decision->causer->id, 'name' => $decision->causer->name] : null,
                    'at' => $decision->created_at?->toIso8601String(),
                ] : null,
            ];
        }
        if ($rows === []) {
            return null;
        }

        $proofs = StockRequestProposal::withoutGlobalScopes()->where('stock_request_id', $request->id)
            ->with(['handoverProof', 'requester:id,name', 'receiver:id,name'])->orderBy('id')->get()
            ->filter(fn (StockRequestProposal $p) => $p->handoverProof !== null)
            ->map(fn (StockRequestProposal $p) => [
                'proposal_id' => $p->id,
                'url' => $p->handoverProof->url(),
                'at' => $p->created_at?->toIso8601String(),
                'gudang' => $p->requester ? ['id' => $p->requester->id, 'name' => $p->requester->name] : null,
                'received_at' => $p->received_at?->toIso8601String(),
                'koordinator' => $p->receiver ? ['id' => $p->receiver->id, 'name' => $p->receiver->name] : null,
                'status' => $p->status,
            ])->values()->all();

        $coordinator = $request->coordinator_requested_by ? User::query()->select('id', 'name')->find($request->coordinator_requested_by) : null;
        $open = collect($rows)->where('short_qty', '>', 0)->count();

        return [
            'order_no' => $order->order_no,
            'fulfillment_status' => $request->status,
            'has_open_shortage' => $open > 0,
            'open_lines' => $open,
            'ordered_qty' => (int) collect($rows)->sum('ordered_qty'),
            'handed_over_qty' => (int) collect($rows)->sum('handed_over_qty'),
            'short_qty' => (int) collect($rows)->sum('short_qty'),
            'last_reported_at' => collect($rows)->max('reported_at'),
            'coordinator' => $coordinator ? ['id' => $coordinator->id, 'name' => $coordinator->name] : null,
            'coordinator_requested_at' => $request->coordinator_requested_at?->toIso8601String(),
            'proofs' => $proofs,
            'items' => $rows,
        ];
    }

    /**
     * True when this order line has a Gudang-reported shortage that still awaits the Admin: the only case in
     * which a reschedule is allowed after the order has started shipping (the remainder is by definition
     * undispatched; delivered/dispatched lines stay refused by the item-status guard).
     */
    public function lineHasOpenShortage(int $orderItemId): bool
    {
        $orderItem = \App\Models\OrderItem::query()->find($orderItemId);
        if (! $orderItem) {
            return false;
        }
        $orderItems = \App\Models\OrderItem::query()->where('order_id', $orderItem->order_id)->get(['id', 'split_from_order_item_id'])->keyBy('id');
        $root = $this->rootOf($orderItems, $orderItemId);
        $sameLine = $orderItems->keys()->filter(fn ($id) => $this->rootOf($orderItems, $id) === $root)->values();

        $demand = StockRequestItem::query()->whereIn('order_item_id', $sameLine)->get(['id', 'remaining_qty']);
        if ($demand->where('remaining_qty', '>', 0)->isEmpty()) {
            return false;
        }

        return ActivityLog::query()->where('subject_type', StockRequestItem::class)->whereIn('subject_id', $demand->pluck('id'))
            ->where('event', 'stock_request.fulfillment_shortage')->exists();
    }

    /** Top of a split chain (a line that was never carved out of another). Bounded: a cycle cannot loop. */
    private function rootOf(\Illuminate\Support\Collection $orderItems, ?int $id): ?int
    {
        for ($hops = 0; $id !== null && $hops < 50; $hops++) {
            $parent = $orderItems->get($id)?->split_from_order_item_id;
            if ($parent === null || ! $orderItems->has($parent)) {
                return $id;
            }
            $id = (int) $parent;
        }

        return $id;
    }
}
