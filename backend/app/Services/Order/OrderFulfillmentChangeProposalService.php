<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderFulfillmentChangeProposal;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

class OrderFulfillmentChangeProposalService
{
    public function __construct(private readonly OrderFulfillmentService $fulfillment) {}

    public function ordersFor(User $actor)
    {
        return Order::query()->where('status', 'diproses')->where('agent_id', $actor->agent_id)
            ->whereHas('items', fn ($query) => $query->where('status', 'diproses')->where('fulfilled_quantity', '>', 0))
            ->with([
                'items' => fn ($query) => $query->where('status', 'diproses')->where('fulfilled_quantity', '>', 0)
                    ->with(['shipment.courier', 'shipment.selfDeliveredBy']),
                'shipments.courier', 'shipments.selfDeliveredBy', 'konsumen',
            ])->latest()->paginate(15);
    }

    public function operationalOrderFor(User $actor, Order $order): Order
    {
        if (! $actor->isRole('gudang')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return Order::query()->whereKey($order->id)->where('agent_id', $actor->agent_id)
            ->where('status', 'diproses')
            ->whereHas('items', fn ($query) => $query->where('status', 'diproses')->where('fulfilled_quantity', '>', 0))
            ->with([
                'items' => fn ($query) => $query->where('status', 'diproses')->where('fulfilled_quantity', '>', 0)
                    ->with(['shipment.courier', 'shipment.selfDeliveredBy']),
                'shipments.courier', 'shipments.selfDeliveredBy', 'konsumen',
            ])
            ->firstOrFail();
    }

    public function proposalsFor(User $actor, string $status = 'pending')
    {
        return OrderFulfillmentChangeProposal::query()->where('agent_id', $actor->agent_id)
            ->when(! in_array($status, ['', 'all'], true), fn ($q) => $q->where('status', $status))
            ->with(['order', 'orderItem', 'proposer', 'decider'])->latest()->paginate(15);
    }

    public function propose(User $actor, Order $order, OrderItem $item, array $data): OrderFulfillmentChangeProposal
    {
        if (! $actor->isRole('gudang') || $order->agent_id !== $actor->agent_id || $item->order_id !== $order->id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($actor, $order, $item, $data) {
            $lockedOrder = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedItem = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== 'diproses' || $lockedItem->status !== 'diproses') {
                throw new ApiException(__('messages.fulfillment.window_closed'), 422);
            }

            $proposal = OrderFulfillmentChangeProposal::withoutGlobalScopes()
                ->where('order_item_id', $lockedItem->id)->where('status', 'pending')->lockForUpdate()->first();
            $values = [
                'agent_id' => $lockedOrder->agent_id, 'order_id' => $lockedOrder->id, 'order_item_id' => $lockedItem->id,
                'proposed_by' => $actor->id, 'base_fulfilled_quantity' => $lockedItem->fulfilled_quantity,
                'proposed_fulfilled_quantity' => $data['fulfilled_quantity'] ?? $lockedItem->fulfilled_quantity,
                'base_requested_delivery_date' => $lockedItem->requested_delivery_date?->toDateString(),
                'proposed_requested_delivery_date' => array_key_exists('requested_delivery_date', $data)
                    ? $data['requested_delivery_date']
                    : $lockedItem->requested_delivery_date?->toDateString(),
                'reason' => $data['reason'] ?? null, 'status' => 'pending',
            ];
            if ($values['proposed_fulfilled_quantity'] > $lockedItem->original_quantity) {
                throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
            }
            if ($proposal) {
                $proposal->update($values);
                ActivityLogger::log($actor->id, $proposal, 'fulfillment_change_proposal.updated', $values['reason'], $this->auditSnapshot($values));
            } else {
                $proposal = OrderFulfillmentChangeProposal::create($values);
                ActivityLogger::log($actor->id, $proposal, 'fulfillment_change_proposal.created', $values['reason'], $this->auditSnapshot($values));
            }

            return $proposal->load(['order', 'orderItem', 'proposer']);
        });
    }

    public function decide(User $actor, OrderFulfillmentChangeProposal $proposal, string $decision, ?string $reason = null): OrderFulfillmentChangeProposal
    {
        if (! $actor->isRole('admin') || $proposal->agent_id !== $actor->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($actor, $proposal, $decision, $reason) {
            $order = Order::withoutGlobalScopes()->whereKey($proposal->order_id)->lockForUpdate()->firstOrFail();
            $locked = OrderFulfillmentChangeProposal::withoutGlobalScopes()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked->load(['order', 'orderItem', 'proposer', 'decider']);
            }
            if ($decision === 'reject') {
                $audit = $this->auditSnapshot($locked->getAttributes());
                $locked->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason]);
                ActivityLogger::log($actor->id, $locked, 'fulfillment_change_proposal.rejected', $reason, $audit);
                return $locked->load(['order', 'orderItem', 'proposer', 'decider']);
            }

            $item = OrderItem::whereKey($locked->order_item_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'diproses' || (int) $item->fulfilled_quantity !== (int) $locked->base_fulfilled_quantity || $item->requested_delivery_date?->toDateString() !== $locked->base_requested_delivery_date?->toDateString()) {
                throw new ApiException('Proposal sudah tidak sesuai dengan keadaan order saat ini.', 409);
            }
            $audit = $this->auditSnapshot($locked->getAttributes());
            if ($locked->proposed_fulfilled_quantity !== $item->fulfilled_quantity) {
                $this->fulfillment->adjustItemQuantity($item, $locked->proposed_fulfilled_quantity, $actor, 'Admin approved Gudang proposal');
            }
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $proposedDate = $locked->proposed_requested_delivery_date?->toDateString();
            if ($proposedDate !== $item->requested_delivery_date?->toDateString()) {
                $this->fulfillment->rescheduleItemDeliveryDate($item, $proposedDate, $actor, 'Admin approved Gudang proposal');
            }
            $locked->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now()]);
            ActivityLogger::log($actor->id, $locked, 'fulfillment_change_proposal.approved', null, $audit);
            ActivityLogger::log($actor->id, $locked, 'fulfillment_change_proposal.applied', null, $audit);

            return $locked->load(['order', 'orderItem', 'proposer', 'decider']);
        });
    }

    private function auditSnapshot(array $values): array
    {
        return [
            'order_id' => $values['order_id'],
            'order_item_id' => $values['order_item_id'],
            'current' => [
                'fulfilled_quantity' => $values['base_fulfilled_quantity'],
                'requested_delivery_date' => $values['base_requested_delivery_date'],
            ],
            'proposed' => [
                'fulfilled_quantity' => $values['proposed_fulfilled_quantity'],
                'requested_delivery_date' => $values['proposed_requested_delivery_date'],
            ],
        ];
    }
}