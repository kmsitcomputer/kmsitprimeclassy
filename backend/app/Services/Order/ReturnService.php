<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Logging\ActivityLogger;
use App\Services\Stock\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Per-item, post-delivery returns — "Konsumen dapat melakukan return setelah
 * item berstatus terkirim... Tidak wajib seluruh order dikembalikan. Jika
 * hanya satu item kembali, refund hanya berdasarkan item tersebut."
 *
 * Item lifecycle for a returned item: terkirim -> pengembalian (the moment a
 * return is requested) -> kembali (once Admin confirms the refund was
 * actually sent). A rejected request reverts the item back to terkirim —
 * the one deliberate exception to OrderItem::TRANSITIONS' forward-only
 * table, since this is a compensating action, not forward progress.
 */
class ReturnService
{
    private const EVIDENCE_DISK = 'public';

    private const EVIDENCE_DIRECTORY = 'returns/evidence';

    public function __construct(private readonly StockService $stockService) {}

    /** @param  array<int, array{order_item_id:int, quantity:int, restock?:bool}>  $items */
    public function requestReturn(Order $order, User $actor, array $items, string $reason, ?UploadedFile $evidence): ReturnRequest
    {
        if (empty($items)) {
            throw new ApiException(__('messages.order.empty_items'), 422);
        }

        return DB::transaction(function () use ($order, $actor, $items, $reason, $evidence) {
            $evidencePath = $evidence?->store(self::EVIDENCE_DIRECTORY, self::EVIDENCE_DISK);

            $return = ReturnRequest::create([
                'order_id' => $order->id,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'evidence_path' => $evidencePath,
                'status' => 'requested',
            ]);

            $totalRefund = 0.0;

            foreach ($items as $line) {
                $item = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereKey($line['order_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($item->status !== 'terkirim') {
                    throw new ApiException(__('messages.return.item_not_delivered'), 422);
                }

                $quantity = (int) $line['quantity'];
                $maxReturnable = $item->fulfilled_quantity - $item->cancelled_quantity - $item->returned_quantity;

                if ($quantity < 1 || $quantity > $maxReturnable) {
                    throw new ApiException(__('messages.return.invalid_quantity'), 422);
                }

                $refundAmount = $quantity * (float) $item->unit_price_snapshot;
                $totalRefund += $refundAmount;

                ReturnItem::create([
                    'return_id' => $return->id,
                    'order_item_id' => $item->id,
                    'quantity_returned' => $quantity,
                    'refund_amount' => $refundAmount,
                    'restock' => $line['restock'] ?? true,
                    'status' => 'pending',
                    'refund_status' => 'not_required',
                ]);

                $item->update([
                    'returned_quantity' => $item->returned_quantity + $quantity,
                    'status' => 'pengembalian',
                ]);
            }

            $return->update(['total_refund_amount' => $totalRefund]);

            ActivityLogger::log($actor->id, $return, 'return.requested', $reason, [
                'order_id' => $order->id, 'actor_role' => $actor->role?->slug, 'total_refund_amount' => $totalRefund,
            ]);

            return $return->fresh('items');
        });
    }

    public function review(ReturnRequest $return, User $actor, bool $approved, ?string $note = null): ReturnRequest
    {
        if (! in_array($return->status, ['requested', 'under_review'], true)) {
            throw new ApiException(__('messages.return.already_reviewed'), 422);
        }

        return DB::transaction(function () use ($return, $actor, $approved, $note) {
            $return = ReturnRequest::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! in_array($return->status, ['requested', 'under_review'], true)) {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            foreach ($return->items()->lockForUpdate()->get() as $returnItem) {
                if ($returnItem->status !== 'pending') {
                    continue;
                }

                $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->first();

                if ($approved) {
                    $returnItem->update(['status' => 'approved', 'refund_status' => 'pending']);

                    // Physical restock is deliberately deferred until Gudang
                    // records the received condition inspection.
                } else {
                    $returnItem->update(['status' => 'rejected', 'refund_status' => 'not_required']);

                    if ($orderItem) {
                        // Compensating reversal, not forward progress — see class docblock.
                        $orderItem->update([
                            'returned_quantity' => max(0, $orderItem->returned_quantity - $returnItem->quantity_returned),
                            'status' => 'terkirim',
                        ]);
                    }
                }
            }

            $return->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            ActivityLogger::log($actor->id, $return, 'return.reviewed', $note, [
                'approved' => $approved, 'actor_role' => $actor->role?->slug,
            ]);

            return $return->fresh('items');
        });
    }

    public function inspectReturn(ReturnItem $returnItem, User $actor, int $received, int $good, int $damaged, ?string $note = null): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $received, $good, $damaged, $note) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();
            if (! $actor->isRole('gudang') || $actor->agent_id !== $order->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 403);
            }
            if ($returnItem->status !== 'approved' || $returnItem->inspected_at) {
                throw new ApiException('Return belum siap diinspeksi atau sudah diproses.', 422);
            }
            if ($received < 0 || $good < 0 || $damaged < 0 || $good + $damaged !== $received || $received > $returnItem->quantity_returned) {
                throw new ApiException('Jumlah inspeksi return tidak valid.', 422);
            }
            $returnItem->update(['quantity_received' => $received, 'good_quantity' => $good, 'damaged_quantity' => $damaged, 'condition_status' => $damaged > 0 && $good > 0 ? 'mixed' : ($damaged > 0 ? 'damaged' : 'good'), 'condition_note' => $note, 'inspected_by' => $actor->id, 'inspected_at' => now(), 'disposition_status' => 'pending_disposition']);

            return $returnItem->fresh();
        });
    }

    public function finalizeInspection(ReturnItem $returnItem, User $actor): ReturnItem
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($returnItem, $actor) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            if (in_array($returnItem->disposition_status, ['restocked', 'damaged_confirmed'], true)) {
                return $returnItem->fresh();
            }
            if ($returnItem->status !== 'approved' || $returnItem->disposition_status !== 'pending_disposition' || ! $returnItem->inspected_at) {
                throw new ApiException('Return belum siap difinalisasi.', 422);
            }
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();
            if ((int) $actor->agent_id !== (int) $order->agent_id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 404);
            }
            $good = (int) $returnItem->good_quantity;
            if ($good > 0) {
                if ($orderItem->isSubSourced()) {
                    // R-03 / decision F: a Sub-sourced customer return restocks the ORIGINAL Sub
                    // Location / Sub domain — never Agent Transit. Damaged quantity is excluded by
                    // construction ($good only).
                    $this->restockSubStock($order, $orderItem, $returnItem, $good, $actor);
                } else {
                    $query = WarehouseStock::withoutGlobalScopes()->where('agent_id', $order->agent_id)->where('stock_type', 'transit')->whereNull('sub_location_id')->when($orderItem->product_id, fn ($q) => $q->where('product_id', $orderItem->product_id)->whereNull('product_variation_id'))->when($orderItem->product_variation_id, fn ($q) => $q->where('product_variation_id', $orderItem->product_variation_id)->whereNull('product_id'))->lockForUpdate();
                    $stock = $query->first() ?? WarehouseStock::create(['agent_id' => $order->agent_id, 'product_id' => $orderItem->product_id, 'product_variation_id' => $orderItem->product_variation_id, 'stock_type' => 'transit', 'quantity' => 0]);
                    $before = $stock->quantity;
                    $stock->increment('quantity', $good);
                    StockMovement::create(['agent_id' => $order->agent_id, 'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id, 'product_variation_id' => $orderItem->product_variation_id, 'type' => 'return_restock', 'stock_type' => 'transit', 'quantity' => $good, 'reference_type' => ReturnItem::class, 'reference_id' => $returnItem->id, 'created_by' => $actor->id, 'note' => "before={$before};after=".($before + $good)]);
                }
            }
            $returnItem->update(['disposition_status' => $good > 0 ? 'restocked' : 'damaged_confirmed', 'restock_processed_at' => $good > 0 ? now() : null]);

            return $returnItem->fresh();
        });
    }

    /**
     * R-03 / decision F: restock good returned Sub-sourced units back into the ORIGINAL Sub Location
     * (`order_item.sub_location_id`) / Sub stock domain with a movement-backed, audited write.
     * If that location is missing or no longer active, the return is rejected explicitly — the goods
     * are NEVER silently redirected to Agent Transit.
     */
    private function restockSubStock(Order $order, OrderItem $orderItem, ReturnItem $returnItem, int $good, User $actor): void
    {
        if (! $orderItem->sub_location_id) {
            throw new ApiException(__('messages.return.sub_location_unavailable'), 422);
        }

        $location = WarehouseSubLocation::withoutGlobalScopes()
            ->whereKey($orderItem->sub_location_id)
            ->lockForUpdate()
            ->first();

        if (! $location || ! $location->is_active) {
            throw new ApiException(__('messages.return.sub_location_unavailable'), 422);
        }

        $query = WarehouseStock::withoutGlobalScopes()
            ->where('stock_type', 'sub')
            ->where('sub_location_id', $location->id)
            ->when($orderItem->product_variation_id, fn ($q) => $q->where('product_variation_id', $orderItem->product_variation_id)->whereNull('product_id'), fn ($q) => $q->where('product_id', $orderItem->product_id)->whereNull('product_variation_id'))
            ->lockForUpdate();

        $stock = $query->first() ?? WarehouseStock::create([
            'agent_id' => $order->agent_id,
            'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id,
            'product_variation_id' => $orderItem->product_variation_id,
            'stock_type' => 'sub',
            'sub_location_id' => $location->id,
            'quantity' => 0,
        ]);

        $before = $stock->quantity;
        $stock->increment('quantity', $good);

        StockMovement::create([
            'agent_id' => $order->agent_id,
            'product_id' => $orderItem->product_variation_id ? null : $orderItem->product_id,
            'product_variation_id' => $orderItem->product_variation_id,
            'type' => 'return_restock',
            'stock_type' => 'sub',
            'sub_location_id' => $location->id,
            'quantity' => $good,
            'reference_type' => ReturnItem::class,
            'reference_id' => $returnItem->id,
            'created_by' => $actor->id,
            'note' => "before={$before};after=".($before + $good),
        ]);
    }

    /** Admin confirms the refund for one return line was actually sent — "sudah dikembalikan dana". */
    public function markItemRefunded(ReturnItem $returnItem, User $actor): ReturnItem
    {
        if ($returnItem->status !== 'approved' || $returnItem->refund_status === 'processed') {
            throw new ApiException(__('messages.return.already_reviewed'), 422);
        }

        return DB::transaction(function () use ($returnItem, $actor) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();

            if ($returnItem->refund_status === 'processed') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            // A refund is a POST-PAID financial adjustment: the money can only
            // be given back once it was actually received. An unsettled DP (or
            // a COD order never marked paid) is refused, not silently refunded.
            $order = $returnItem->returnRequest?->order;

            if ($order && ! $order->isFullyPaid()) {
                throw new ApiException(__('messages.payment.not_fully_paid'), 422);
            }

            $returnItem->update(['refund_status' => 'processed']);

            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->first();

            if ($orderItem?->canTransitionTo('kembali') || $orderItem?->status === 'pengembalian') {
                $orderItem->update([
                    'status' => 'kembali',
                    'refund_quantity' => $orderItem->refund_quantity + $returnItem->quantity_returned,
                ]);
            }

            $return = $returnItem->returnRequest;
            if ($return && $return->items()->where('refund_status', '!=', 'processed')->where('status', 'approved')->doesntExist()) {
                $return->update(['status' => 'completed']);
            }

            ActivityLogger::log($actor->id, $returnItem, 'return_item.refund_marked', null, [
                'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * "Kurir harus melakukan pickup" — a distinct claim on the physical
     * pickup itself, separate from (and normally happening well before) the
     * eventual courierConfirmReturn(). Once claimed, the item drops out of
     * every OTHER kurir's return queue (CourierDashboardController::returns)
     * even though it's still sitting in 'pengembalian' awaiting resolution.
     */
    public function pickupReturn(ReturnItem $returnItem, User $actor, ?string $note = null): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $note) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();

            if ($orderItem->status !== 'pengembalian') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            $this->claimReturnPickup($returnItem, $actor);

            if ($note !== null) {
                $returnItem->update(['condition_note' => $note]);
            }

            ActivityLogger::log($actor->id, $returnItem, 'return_item.picked_up', $note, [
                'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * Kurir's own action on a 'pengembalian' item — purely a physical-
     * logistics confirmation, never touching price/refund_status/payment
     * (Blueprint: "Kurir tidak boleh... mengubah fee... mengubah payment").
     * The actual refund money movement stays admin-only (markItemRefunded).
     *   - received=true:  pengembalian -> kembali (item physically collected).
     *   - received=false: pengembalian -> terkirim (customer didn't hand it
     *     over after all — same reversal exception as review(false)).
     */
    public function courierConfirmReturn(ReturnItem $returnItem, User $actor, bool $received): ReturnItem
    {
        return DB::transaction(function () use ($returnItem, $actor, $received) {
            $returnItem = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($returnItem->order_item_id)->lockForUpdate()->firstOrFail();

            if ($orderItem->status !== 'pengembalian') {
                throw new ApiException(__('messages.return.already_reviewed'), 422);
            }

            $this->claimReturnPickup($returnItem, $actor);

            if ($received) {
                $orderItem->update(['status' => 'kembali']);
            } else {
                $returnItem->update(['status' => 'rejected', 'refund_status' => 'not_required']);
                $orderItem->update([
                    'returned_quantity' => max(0, $orderItem->returned_quantity - $returnItem->quantity_returned),
                    'status' => 'terkirim',
                ]);
            }

            ActivityLogger::log($actor->id, $returnItem, 'return_item.courier_confirmed', null, [
                'received' => $received, 'actor_role' => $actor->role?->slug,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * The same claim-on-first-action pattern as CourierService::selfAssignIfUnassigned,
     * scoped per return item — "status order request pengembalian bisa
     * dilihat oleh semua kurir, setelah ada kurir yang pickup... tidak boleh
     * tampil di kurir lain."
     */
    private function claimReturnPickup(ReturnItem $returnItem, User $actor): void
    {
        $courier = $actor->courierProfile;

        if (! $courier) {
            throw new ApiException(__('messages.courier.no_profile'), 422);
        }

        if ($returnItem->courier_id === null) {
            $returnItem->update(['courier_id' => $courier->id]);
        } elseif ($returnItem->courier_id !== $courier->id) {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }
}
