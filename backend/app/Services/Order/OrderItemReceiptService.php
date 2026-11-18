<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\OrderItem;
use App\Models\OrderItemReceipt;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\PayloadFingerprint;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REM-07 / D-06 A. Records ACTUAL per-item recipient receipt (partial and multiple events allowed).
 *
 * Authority mirrors DeliveryVerificationPolicy: Admin of the order's own Agent, or Super Admin.
 * It never touches shipments.delivered_at, item status, payment, stock or commission; the
 * existing delivery workflow is unchanged. Quantities are bounded by the item's billed
 * (fulfilled) quantity across ALL receipt events, under a row lock on the item.
 */
class OrderItemReceiptService
{
    /** Item states in which the units have actually reached the recipient. */
    public const ARRIVED_STATUSES = ['terkirim', 'pengembalian', 'kembali'];

    public function record(OrderItem $item, User $actor, int $quantity, string $receivedOn, ?string $receivedAt, ?int $evidenceMediaId, ?string $evidenceNote, string $idempotencyKey): OrderItemReceipt
    {
        $payload = ['item' => $item->id, 'qty' => $quantity, 'on' => $receivedOn, 'at' => $receivedAt, 'media' => $evidenceMediaId, 'note' => $evidenceNote !== null ? trim($evidenceNote) : null];
        $fingerprint = PayloadFingerprint::of($payload);

        try {
            return DB::transaction(function () use ($item, $actor, $quantity, $receivedOn, $receivedAt, $evidenceMediaId, $evidenceNote, $idempotencyKey, $fingerprint) {
                $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                // Unscoped on purpose: authority is decided explicitly below, not by the ambient agent scope.
                $order = \App\Models\Order::withoutGlobalScopes()->findOrFail($locked->order_id);

                $allowed = $actor->isRole('super_admin')
                    || ($actor->isRole('admin') && (int) $order->agent_id === (int) $actor->agent_id);
                if (! $allowed) {
                    throw new ApiException('Tidak berwenang mencatat penerimaan item ini.', 403);
                }

                $existing = OrderItemReceipt::query()->where('verified_by', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $this->replay($existing, $fingerprint);
                }

                if ($quantity < 1) {
                    throw new ApiException('Jumlah diterima harus positif.', 422);
                }
                if (! in_array($locked->status, self::ARRIVED_STATUSES, true)) {
                    throw new ApiException('Item belum tiba/diterima sehingga penerimaan tidak dapat dicatat.', 422);
                }
                $date = Carbon::createFromFormat('Y-m-d', $receivedOn)->startOfDay();
                if ($date->isFuture() && ! $date->isToday()) {
                    throw new ApiException('Tanggal penerimaan tidak boleh di masa depan.', 422);
                }
                if ($date->lt($order->created_at->copy()->startOfDay())) {
                    throw new ApiException('Tanggal penerimaan tidak boleh sebelum order dibuat.', 422);
                }
                $at = $receivedAt !== null ? Carbon::parse($receivedAt) : null;
                if ($at !== null && ($at->isFuture() || $at->toDateString() !== $date->toDateString())) {
                    throw new ApiException('Waktu penerimaan tidak konsisten dengan tanggal penerimaan.', 422);
                }

                $already = (int) OrderItemReceipt::query()->where('order_item_id', $locked->id)->sum('quantity');
                if ($already + $quantity > (int) $locked->fulfilled_quantity) {
                    throw new ApiException('Jumlah penerimaan melebihi kuantitas item.', 422, ['quantity' => 'Melebihi sisa kuantitas.']);
                }

                $receipt = OrderItemReceipt::create([
                    'order_item_id' => $locked->id, 'order_id' => $order->id, 'agent_id' => $order->agent_id,
                    'quantity' => $quantity, 'received_on' => $date->toDateString(), 'received_at' => $at,
                    'evidence_media_id' => $evidenceMediaId, 'evidence_note' => $evidenceNote !== null && trim($evidenceNote) !== '' ? trim($evidenceNote) : null,
                    'verified_by' => $actor->id, 'verified_at' => now(), // truthful recording time
                    'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint,
                ]);

                ActivityLogger::log($actor->id, $order, 'order_item.receipt_recorded', null, [
                    'order_item_id' => $locked->id, 'receipt_id' => $receipt->id, 'quantity' => $quantity,
                    'received_on' => $date->toDateString(), 'actor_role' => $actor->role?->slug,
                ]);

                return $receipt;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'oir_verifier_key_unique')) {
                return $this->replay(
                    OrderItemReceipt::query()->where('verified_by', $actor->id)->where('idempotency_key', $idempotencyKey)->firstOrFail(),
                    $fingerprint
                );
            }
            throw $e;
        }
    }

    private function replay(OrderItemReceipt $existing, string $fingerprint): OrderItemReceipt
    {
        if ($existing->request_fingerprint !== $fingerprint) {
            throw new ApiException('Idempotency key dipakai dengan data berbeda.', 409);
        }

        return $existing;
    }
}
