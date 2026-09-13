<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Courier assignment + delivery progress, scoped per Shipment rather than per
 * Order — "satu order bisa beberapa kurir karena ada kemungkinan produk yang
 * bisa di reschedule" (Blueprint §Courier): a rescheduled item can split onto
 * its own Shipment (see OrderFulfillmentService::rescheduleItemDeliveryDate),
 * so different items on the same order may end up with different couriers,
 * each progressing independently.
 *
 * Unlike agent/sales fees (earned the instant an order is placed, regardless
 * of delivery outcome), a courier's fee is only earned once THEY actually
 * complete the delivery — recordCommissionsForItems() is only ever called
 * once a shipment (or, for an admin/agen bulk override, the whole order)
 * reaches 'terkirim'.
 */
class CourierService
{
    public function __construct(private readonly MediaService $mediaService) {}

    /**
     * "Kurir wajib berada di bawah Agen" — a courier may only ever be
     * assigned to a shipment from their own agent's branch, never another
     * agent's (Blueprint: "Kurir tidak boleh... melihat data agen lain").
     */
    public function assignCourier(Shipment $shipment, Courier $courier, User $actor): Shipment
    {
        $agentId = $shipment->order()->value('agent_id');

        if ($courier->agent_id !== $agentId || ! $courier->is_active) {
            throw new ApiException(__('messages.courier.invalid_assignment'), 422);
        }

        return DB::transaction(function () use ($shipment, $courier, $actor) {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $previousCourierId = $shipment->courier_id;
            $shipment->update(['courier_id' => $courier->id]);

            ActivityLogger::log($actor->id, $shipment, 'shipment.courier_assigned', null, [
                'order_id' => $shipment->order_id, 'courier_id' => $courier->id,
                'previous_courier_id' => $previousCourierId, 'actor_role' => $actor->role?->slug,
            ]);

            return $shipment->fresh();
        });
    }

    /**
     * A courier picking up an unassigned delivery self-assigns the moment
     * they mark it 'dikirim' — never overwrites an existing assignment to a
     * different courier (that shipment isn't theirs to take).
     */
    public function selfAssignIfUnassigned(Shipment $shipment, User $kurirActor): void
    {
        $courier = $kurirActor->courierProfile;

        if (! $courier) {
            throw new ApiException(__('messages.courier.no_profile'), 422);
        }

        $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

        if ($shipment->courier_id === null) {
            $shipment->update(['courier_id' => $courier->id]);
        } elseif ($shipment->courier_id !== $courier->id) {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }

    /**
     * The kurir-facing counterpart of OrderService::updateStatus — moves every
     * item on THIS shipment (never its siblings on other shipments of the
     * same order) diproses->dikirim or dikirim->terkirim, keeps the
     * shipment's own tracking fields in sync, and recomputes the order's
     * denormalized overall status from all of its shipments' progress.
     */
    public function updateShipmentStatus(Shipment $shipment, string $newStatus, User $actor, ?UploadedFile $proof = null): Shipment
    {
        if (! in_array($newStatus, ['dikirim', 'terkirim'], true)) {
            throw new ApiException(__('messages.order.status_endpoint_required', ['status' => $newStatus]), 422);
        }

        if ($newStatus === 'dikirim' && $actor->isRole('kurir')) {
            $this->selfAssignIfUnassigned($shipment, $actor);
        }

        if ($newStatus === 'terkirim' && $actor->isRole('kurir')) {
            $assignedCourierUserId = $shipment->fresh()->courier?->user_id;

            if ($assignedCourierUserId !== null && $assignedCourierUserId !== $actor->id) {
                throw new ApiException(__('messages.courier.not_your_delivery'), 403);
            }

            // "Kurir harus memasukan bukti pengiriman jika ingin merubah
            // status kirim dari dikirim jadi terkirim" — a business rule, not
            // just a frontend prompt: no photo, no transition, regardless of
            // what the request claims.
            if (! $proof) {
                throw new ApiException(__('messages.courier.delivery_proof_required'), 422);
            }
        }

        return DB::transaction(function () use ($shipment, $newStatus, $actor, $proof) {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $items = OrderItem::query()->where('shipment_id', $shipment->id)->lockForUpdate()->get();

            $anyTransitioned = false;
            foreach ($items as $item) {
                if ($item->canTransitionTo($newStatus)) {
                    $item->update(['status' => $newStatus]);
                    $anyTransitioned = true;
                }
            }

            if (! $anyTransitioned) {
                throw new InvalidStateTransitionException($items->first()?->status ?? $shipment->status, $newStatus, 'shipment');
            }

            if ($proof && $newStatus === 'terkirim') {
                $media = $this->mediaService->store($proof, 'shipment_proof', $shipment, $actor);
                $shipment->update(['proof_media_id' => $media->id]);
            }

            $this->syncShipmentProgress($shipment, $newStatus);

            ActivityLogger::log($actor->id, $shipment, 'shipment.status_changed', null, [
                'order_id' => $shipment->order_id, 'to' => $newStatus, 'actor_role' => $actor->role?->slug,
            ]);

            if ($newStatus === 'terkirim') {
                $this->recordCommissionsForItems($items->fresh(), $shipment->courier);
            }

            $this->recomputeOrderStatus($shipment->order_id);

            return $shipment->fresh();
        });
    }

    private function syncShipmentProgress(Shipment $shipment, string $itemStatus): void
    {
        $shipment->update(match ($itemStatus) {
            'dikirim' => ['status' => 'in_transit', 'shipped_at' => $shipment->shipped_at ?? now()],
            'terkirim' => ['status' => 'delivered', 'delivered_at' => now()],
            default => [],
        });
    }

    /**
     * The order's own `status` is a denormalized read of its most-behind
     * shipment: 'terkirim' only once every (non-cancelled) item across every
     * shipment has arrived; 'dikirim' as soon as any one of them has left.
     * Never moves the order backwards, and never touches it before dispatch
     * (still 'diterima'/'dibatalkan') — those remain OrderService's calls.
     */
    private function recomputeOrderStatus(int $orderId): void
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        if (! $order || ! in_array($order->status, ['diproses', 'dikirim', 'terkirim'], true)) {
            return;
        }

        $items = OrderItem::query()->where('order_id', $orderId)
            ->whereNotIn('status', ['dibatalkan'])->get();

        if ($items->isEmpty()) {
            return;
        }

        $newStatus = match (true) {
            $items->every(fn (OrderItem $i) => in_array($i->status, ['terkirim', 'pengembalian', 'kembali'], true)) => 'terkirim',
            $items->contains(fn (OrderItem $i) => in_array($i->status, ['dikirim', 'terkirim', 'pengembalian', 'kembali'], true)) => 'dikirim',
            default => $order->status,
        };

        $rank = ['diproses' => 1, 'dikirim' => 2, 'terkirim' => 3];

        if (($rank[$newStatus] ?? 0) > ($rank[$order->status] ?? 0)) {
            $order->update(['status' => $newStatus]);
        }
    }

    /**
     * Bulk admin/agen/super_admin override path (OrderService::updateStatus)
     * — groups the order's items by whichever shipment each currently sits
     * on, crediting each group's own assigned courier. Idempotent: never
     * re-commissions an item a per-shipment action already paid out.
     */
    public function recordCommissionsOnDelivery(Order $order): void
    {
        $itemsByShipment = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('status', 'terkirim')
            ->with('shipment.courier')
            ->get()
            ->groupBy('shipment_id');

        foreach ($itemsByShipment as $items) {
            $this->recordCommissionsForItems($items, $items->first()->shipment?->courier);
        }
    }

    /** @param  iterable<OrderItem>  $items */
    public function recordCommissionsForItems(iterable $items, ?Courier $courier): void
    {
        if (! $courier?->user_id) {
            return;
        }

        foreach ($items as $item) {
            if ((float) $item->courier_fee_amount <= 0) {
                continue;
            }

            if (Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->exists()) {
                continue;
            }

            Commission::create([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'beneficiary_user_id' => $courier->user_id,
                'beneficiary_role' => 'courier',
                'amount' => $item->courier_fee_amount,
                'status' => 'pending',
                'earned_at' => now(),
            ]);
        }
    }
}
