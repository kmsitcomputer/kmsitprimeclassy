<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\StockHandover;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * R-02: Transit <-> Sub stock requests, with the locked authority chain
 *
 *   replenish: Sales-Kurir-Sub requests -> Admin approves -> Gudang executes the physical Transit->Sub
 *              transfer (movements + handover) -> Sales-Kurir-Sub confirms receipt
 *   return:    Sales-Kurir-Sub requests -> Admin approves -> Gudang receives the goods
 *              (Sub decreases, Transit increases, movements + handover)
 *
 * Creating/approving/rejecting/cancelling NEVER touches stock; only execute() does, once (the request row
 * is locked and its status is the idempotency guard), through StockTransferService so the move is
 * movement-backed and audited exactly like every other warehouse transfer.
 */
class SubStockRequestService
{
    public function __construct(
        private readonly SubLocationOwnershipService $ownership,
        private readonly SubStockService $subStock,
        private readonly StockTransferService $transfers,
    ) {}

    public function create(User $actor, string $direction, array $items, ?string $note = null, ?string $idempotencyKey = null): SubStockRequest
    {
        if (! $actor->isRole(Role::SALES_KURIR_SUB) || ! $actor->agent_id) {
            throw new ApiException('Hanya Sales-Kurir-Sub yang dapat membuat permintaan stok Sub.', 403);
        }
        if (! in_array($direction, [SubStockRequest::REPLENISH, SubStockRequest::RETURN], true)) {
            throw new ApiException('Arah permintaan tidak valid.', 422);
        }
        $location = $this->ownership->locationOf($actor)
            ?? throw new ApiException('Anda belum memiliki Sub Location aktif.', 422);
        $normalized = $this->normalizeItems($items);

        if ($idempotencyKey) {
            $existing = SubStockRequest::withoutGlobalScopes()->where('requested_by', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('items');
            }
        }

        try {
            return DB::transaction(function () use ($actor, $direction, $normalized, $note, $idempotencyKey, $location) {
                if ($direction === SubStockRequest::RETURN) {
                    foreach ($normalized as $row) {
                        $sellable = $this->subStock->sellable($location->id, $row['product_variation_id'] ? null : $row['product_id'], $row['product_variation_id'])['sellable'];
                        if ($row['quantity'] > $sellable) {
                            throw new ApiException('Jumlah retur melebihi stok Sub yang tersedia.', 422, ['quantity' => 'Melebihi stok Sub tersedia.']);
                        }
                    }
                }
                $request = SubStockRequest::withoutGlobalScopes()->create([
                    'agent_id' => $actor->agent_id, 'sub_location_id' => $location->id, 'request_number' => $this->uniqueNumber(),
                    'direction' => $direction, 'status' => 'requested', 'requested_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'note' => $note,
                ]);
                foreach ($normalized as $row) {
                    $request->items()->create($row);
                }
                $this->log($actor, $request, 'sub_stock_request.created');

                return $request->load('items');
            });
        } catch (QueryException $e) {
            if ($idempotencyKey && str_contains($e->getMessage(), 'sub_stock_requests_idempotency_unique')) {
                return SubStockRequest::withoutGlobalScopes()->where('requested_by', $actor->id)->where('idempotency_key', $idempotencyKey)->firstOrFail()->load('items');
            }
            throw $e;
        }
    }

    public function approve(User $actor, SubStockRequest $request): SubStockRequest
    {
        $this->assertRole($actor, 'admin', 'Hanya Admin yang dapat menyetujui permintaan stok Sub.');

        return DB::transaction(function () use ($actor, $request) {
            $locked = $this->lock($request, $actor);
            if ($locked->status === 'approved') {
                return $locked->load('items');
            }
            if ($locked->status !== 'requested') {
                throw new ApiException('Permintaan ini tidak dapat disetujui.', 422);
            }
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->log($actor, $locked, 'sub_stock_request.approved');

            return $locked->fresh()->load('items');
        });
    }

    public function reject(User $actor, SubStockRequest $request, string $reason): SubStockRequest
    {
        $this->assertRole($actor, 'admin', 'Hanya Admin yang dapat menolak permintaan stok Sub.');

        return DB::transaction(function () use ($actor, $request, $reason) {
            $locked = $this->lock($request, $actor);
            if ($locked->status === 'rejected') {
                return $locked->load('items');
            }
            if ($locked->status !== 'requested') {
                throw new ApiException('Permintaan ini tidak dapat ditolak.', 422);
            }
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->log($actor, $locked, 'sub_stock_request.rejected');

            return $locked->fresh()->load('items');
        });
    }

    public function cancel(User $actor, SubStockRequest $request): SubStockRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $locked = $this->lock($request, $actor);
            if (! $actor->isRole(Role::SALES_KURIR_SUB) || $locked->requested_by !== $actor->id) {
                throw new ApiException('Hanya pemohon yang dapat membatalkan permintaan.', 403);
            }
            if ($locked->status === 'cancelled') {
                return $locked->load('items');
            }
            if ($locked->status !== 'requested') {
                throw new ApiException('Hanya permintaan yang belum disetujui yang dapat dibatalkan.', 422);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->log($actor, $locked, 'sub_stock_request.cancelled');

            return $locked->fresh()->load('items');
        });
    }

    /** Gudang performs the physical move. The ONLY method here that changes stock. Idempotent. */
    public function execute(User $actor, SubStockRequest $request): SubStockRequest
    {
        $this->assertRole($actor, 'gudang', 'Hanya Gudang yang dapat mengeksekusi permintaan stok Sub.');

        return DB::transaction(function () use ($actor, $request) {
            $locked = $this->lock($request, $actor);
            if (in_array($locked->status, ['executed', 'received'], true)) {
                return $locked->load(['items', 'transfer.handover']); // retry: nothing moves twice
            }
            if ($locked->status !== 'approved') {
                throw new ApiException('Permintaan harus disetujui Admin sebelum dieksekusi Gudang.', 422);
            }
            $location = WarehouseSubLocation::withoutGlobalScopes()->whereKey($locked->sub_location_id)->where('agent_id', $locked->agent_id)->lockForUpdate()->first();
            if (! $location || ! $location->is_active || $location->owner_user_id !== $locked->requested_by) {
                throw new ApiException('Sub Location tidak lagi aktif atau bukan milik pemohon.', 422);
            }

            $transfer = $this->transfers->executeForSubRequest($actor, $locked->agent_id, $locked->direction, $locked->sub_location_id, $locked->requested_by, $locked->items()->get(), $locked->request_number);
            $locked->update(['status' => 'executed', 'executed_by' => $actor->id, 'executed_at' => now(), 'stock_transfer_id' => $transfer->id]);
            $this->log($actor, $locked, 'sub_stock_request.executed', ['stock_transfer_id' => $transfer->id]);

            return $locked->fresh()->load(['items', 'transfer.handover']);
        });
    }

    /** Sales-Kurir-Sub confirms it received replenished goods (closes the handover). Idempotent. */
    public function receive(User $actor, SubStockRequest $request): SubStockRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $locked = $this->lock($request, $actor);
            if (! $actor->isRole(Role::SALES_KURIR_SUB) || $locked->requested_by !== $actor->id) {
                throw new ApiException('Hanya pemohon yang dapat mengonfirmasi penerimaan.', 403);
            }
            if ($locked->direction !== SubStockRequest::REPLENISH) {
                throw new ApiException('Penerimaan hanya berlaku untuk pengisian stok Sub.', 422);
            }
            if ($locked->status === 'received') {
                return $locked->load(['items', 'transfer.handover']);
            }
            if ($locked->status !== 'executed') {
                throw new ApiException('Barang belum diserahkan oleh Gudang.', 422);
            }
            StockHandover::withoutGlobalScopes()->where('stock_transfer_id', $locked->stock_transfer_id)->update(['status' => 'received', 'received_by' => $actor->id, 'received_at' => now()]);
            $locked->update(['status' => 'received', 'received_by' => $actor->id, 'received_at' => now()]);
            $this->log($actor, $locked, 'sub_stock_request.received');

            return $locked->fresh()->load(['items', 'transfer.handover']);
        });
    }

    private function lock(SubStockRequest $request, User $actor): SubStockRequest
    {
        return SubStockRequest::withoutGlobalScopes()->with('items')->whereKey($request->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
    }

    private function assertRole(User $actor, string $role, string $message): void
    {
        if (! $actor->isRole($role) || ! $actor->agent_id) {
            throw new ApiException($message, 403);
        }
    }

    /** @return list<array{product_id:?int, product_variation_id:?int, quantity:int}> */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new ApiException('Permintaan harus memiliki item.', 422);
        }
        $seen = [];
        $rows = [];
        foreach ($items as $item) {
            $productId = ! empty($item['product_id']) ? (int) $item['product_id'] : null;
            $variationId = ! empty($item['product_variation_id']) ? (int) $item['product_variation_id'] : null;
            $quantity = (int) ($item['quantity'] ?? 0);
            if (($productId === null) === ($variationId === null) || $quantity < 1) {
                throw new ApiException('Setiap item harus memiliki tepat satu target dan jumlah positif.', 422);
            }
            $key = $variationId ? 'v:'.$variationId : 'p:'.$productId;
            if (isset($seen[$key])) {
                throw new ApiException('Target item tidak boleh duplikat.', 422);
            }
            $seen[$key] = true;
            $valid = $variationId
                ? DB::table('product_variations')->where('id', $variationId)->whereNull('deleted_at')->exists()
                : DB::table('products')->where('id', $productId)->whereNull('deleted_at')->exists();
            if (! $valid) {
                throw new ApiException('Product/variation tidak valid.', 422);
            }
            $rows[] = ['product_id' => $productId, 'product_variation_id' => $variationId, 'quantity' => $quantity];
        }

        return $rows;
    }

    private function log(User $actor, SubStockRequest $request, string $event, array $extra = []): void
    {
        ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => SubStockRequest::class, 'subject_id' => $request->id, 'event' => $event, 'properties' => ['agent_id' => $request->agent_id, 'direction' => $request->direction] + $extra]);
    }

    private function uniqueNumber(): string
    {
        do {
            $number = 'SSR-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));
        } while (DB::table('sub_stock_requests')->where('request_number', $number)->exists());

        return $number;
    }
}
