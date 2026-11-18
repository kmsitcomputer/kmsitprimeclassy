<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemReceipt;
use App\Models\ReturnItem;
use App\Models\ReturnItemReceiptAllocation;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * REM-07 / D-06 A: records WHICH receipt event(s) the accepted physical units of one return item came from.
 * Authority = receipt authority (Admin of the order's Agent / Super Admin). All-or-nothing: the allocation
 * must account for exactly quantity_received (good + damaged). One-shot and append-only — a replay of the
 * identical allocation returns the existing rows, a different one is a 409. Nothing is ever inferred.
 */
class ReturnReceiptAllocationService
{
    /** @param  list<array{receipt_id:int, quantity:int}>  $allocations  @return list<ReturnItemReceiptAllocation> */
    public function record(ReturnItem $returnItem, User $actor, array $allocations, ?string $note): array
    {
        return DB::transaction(function () use ($returnItem, $actor, $allocations, $note) {
            $item = ReturnItem::query()->whereKey($returnItem->id)->lockForUpdate()->firstOrFail();
            $orderItem = OrderItem::query()->whereKey($item->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->findOrFail($orderItem->order_id);

            if (! ($actor->isRole('super_admin') || ($actor->isRole('admin') && (int) $order->agent_id === (int) $actor->agent_id))) {
                throw new ApiException('Tidak berwenang mengalokasikan retur ini.', 403);
            }
            // Accepted physical return only: approved AND inspected; requested/rejected never allocate.
            if ($item->status !== 'approved' || $item->inspected_at === null || (int) $item->quantity_received < 1) {
                throw new ApiException('Hanya retur fisik yang sudah diterima/diinspeksi yang dapat dialokasikan.', 422);
            }

            $normalized = [];
            foreach ($allocations as $a) {
                $rid = (int) ($a['receipt_id'] ?? 0);
                $q = (int) ($a['quantity'] ?? 0);
                if ($q < 1 || isset($normalized[$rid])) {
                    throw new ApiException('Alokasi tidak valid.', 422);
                }
                $normalized[$rid] = $q;
            }
            if (array_sum($normalized) !== (int) $item->quantity_received) {
                throw new ApiException('Total alokasi harus sama dengan jumlah retur fisik yang diterima.', 422);
            }

            $existing = ReturnItemReceiptAllocation::query()->where('return_item_id', $item->id)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->pluck('quantity', 'order_item_receipt_id')->map(fn ($v) => (int) $v)->sortKeys()->all() === collect($normalized)->sortKeys()->all()) {
                    return $existing->all();
                }
                throw new ApiException('Retur ini sudah dialokasikan dengan data berbeda.', 409);
            }

            $receipts = OrderItemReceipt::query()->where('order_item_id', $orderItem->id)->whereIn('id', array_keys($normalized))->lockForUpdate()->get()->keyBy('id');
            if ($receipts->count() !== count($normalized)) {
                throw new ApiException('Receipt bukan milik item order ini.', 422);
            }
            foreach ($normalized as $rid => $q) {
                $used = (int) ReturnItemReceiptAllocation::query()->where('order_item_receipt_id', $rid)->sum('quantity');
                if ($used + $q > $receipts[$rid]->quantity) {
                    throw new ApiException('Alokasi melebihi kuantitas penerimaan.', 422);
                }
            }

            $rows = [];
            foreach ($normalized as $rid => $q) {
                $rows[] = ReturnItemReceiptAllocation::create([
                    'return_item_id' => $item->id, 'order_item_receipt_id' => $rid, 'quantity' => $q,
                    'allocated_by' => $actor->id, 'allocated_at' => now(), 'evidence_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                ]);
            }
            ActivityLogger::log($actor->id, $order, 'return_item.receipt_allocated', null, ['return_item_id' => $item->id, 'allocations' => $normalized, 'actor_role' => $actor->role?->slug]);

            return $rows;
        });
    }
}
