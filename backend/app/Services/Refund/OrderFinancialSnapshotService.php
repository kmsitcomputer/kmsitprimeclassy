<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderFinancialSnapshotUnit;
use App\Models\OrderItem;
use App\Models\OrderItemFinancialBasis;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B-01: the immutable, order-wide financial allocation snapshot.
 *
 * Created once at the first basis-requiring event (caller already holds the Order lock), then
 * reused forever. Later events never re-allocate, which is what keeps voucher shares conserved
 * across staggered line freezes.
 */
class OrderFinancialSnapshotService
{
    public const TRIGGER_FIRST_HANDOVER = 'first_handover';

    public const TRIGGER_RETURN_APPROVAL = 'return_approval';

    public const TRIGGER_FULFILLMENT_SHORTFALL = 'fulfillment_shortfall';

    public const TRIGGER_ORDER_CANCELLATION = 'order_cancellation';

    public const TRIGGER_PROVISIONAL_LEGACY = 'provisional_legacy_backfill';

    public function __construct(private readonly RefundAudit $audit) {}

    /** Caller MUST already hold the orders row lock (Order-first protocol). */
    public function ensure(
        Order $order,
        string $trigger,
        ?User $actor = null,
        string $provenance = 'frozen',
        ?int $migrationBatchId = null,
        ?array $unitOverride = null,
    ): OrderFinancialSnapshot {
        $existing = OrderFinancialSnapshot::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        $lines = $this->captureLines($order, $unitOverride);
        $units = [];

        foreach ($lines as $line) {
            for ($index = 1; $index <= $line['quantity']; $index++) {
                $units[] = [
                    'order_item_id' => $line['order_item_id'],
                    'unit_index' => $index,
                    'unit_gross_cents' => Money::cents($line['unit_gross']),
                ];
            }
        }

        // Canonical subtotal law: subtotal = SUM(unit_price_snapshot * fulfilled_quantity).
        // Derive the basis from the frozen units themselves instead of trusting a possibly stale
        // order.subtotal_amount (historical rows and cancelled/partial orders can diverge).
        $subtotalCents = array_sum(array_column($units, 'unit_gross_cents'));
        $voucherCents = Money::cents($order->effectiveDiscountAmount(Money::float($subtotalCents)));

        if ($units === []) {
            throw new ApiException('Order tidak memiliki unit yang dapat di-snapshot.', 422, [
                'code' => 'order_has_no_billable_units',
            ]);
        }

        $allocated = $this->allocate($units, $voucherCents);
        $checksum = $this->checksum($order->id, $subtotalCents, $voucherCents, $units, $allocated);

        $snapshot = DB::transaction(function () use (
            $order, $trigger, $actor, $provenance, $migrationBatchId, $lines,
            $units, $allocated, $subtotalCents, $voucherCents, $checksum
        ) {
            $snapshot = OrderFinancialSnapshot::create([
                'order_id' => $order->id,
                'snapshot_version' => 1,
                'trigger' => $trigger,
                'subtotal_basis' => Money::amount($subtotalCents),
                'effective_voucher_basis' => Money::amount($voucherCents),
                'voucher_source_type' => $order->voucher_id ? 'voucher' : null,
                'voucher_source_id' => $order->voucher_id,
                'allocation_method' => 'largest_remainder_unit_v1',
                'allocation_checksum' => $checksum,
                'frozen_at' => now(),
                'frozen_by' => $actor?->id,
                'provenance' => $provenance,
                'migration_batch_id' => $migrationBatchId,
                'metadata' => [
                    'trigger' => $trigger,
                    'line_count' => count($lines),
                    'unit_count' => count($units),
                ],
            ]);

            foreach ($units as $i => $unit) {
                $gross = $unit['unit_gross_cents'];
                $discount = $allocated[$i];

                OrderFinancialSnapshotUnit::create([
                    'snapshot_id' => $snapshot->id,
                    'order_item_id' => $unit['order_item_id'],
                    'unit_index' => $unit['unit_index'],
                    'unit_gross' => Money::amount($gross),
                    'allocated_discount' => Money::amount($discount),
                    'unit_net' => Money::amount($gross - $discount),
                ]);
            }

            $lineTotals = [];
            foreach ($lines as $line) {
                $lineTotals[$line['order_item_id']] = [
                    'quantity' => $line['quantity'],
                    'unit_gross' => 0,
                    'discount' => 0,
                ];
            }
            foreach ($units as $i => $unit) {
                $lineTotals[$unit['order_item_id']]['unit_gross'] += $unit['unit_gross_cents'];
                $lineTotals[$unit['order_item_id']]['discount'] += $allocated[$i];
            }

            foreach ($lines as $line) {
                $totals = $lineTotals[$line['order_item_id']];

                OrderItemFinancialBasis::create([
                    'order_id' => $order->id,
                    'order_item_id' => $line['order_item_id'],
                    'snapshot_id' => $snapshot->id,
                    'qty_basis' => $line['quantity'],
                    'unit_gross_basis' => $line['unit_gross'],
                    'line_gross_basis' => Money::amount($totals['unit_gross']),
                    'allocated_discount_total' => Money::amount($totals['discount']),
                    'line_net_basis' => Money::amount($totals['unit_gross'] - $totals['discount']),
                    'allocation_checksum' => $checksum,
                    'frozen_at' => now(),
                    'provenance' => $provenance,
                    'migration_batch_id' => $migrationBatchId,
                ]);
            }

            $this->validateOrFail($snapshot, $units, $allocated, $subtotalCents, $voucherCents);

            $this->audit->record(
                'snapshot.created',
                $order,
                eventKey: 'snapshot:'.$order->id,
                actor: $actor,
                payload: [
                    'snapshot_id' => $snapshot->id,
                    'trigger' => $trigger,
                    'checksum' => $checksum,
                    'subtotal_cents' => $subtotalCents,
                    'voucher_cents' => $voucherCents,
                    'unit_count' => count($units),
                ],
            );

            return $snapshot->fresh(['units', 'bases']);
        });

        return $snapshot;
    }

    /**
     * Mutation guard: once the order snapshot exists, structural/financial edits are rejected.
     * Shortfall/return consumption is the only permitted movement and never changes allocations.
     */
    public function assertMutable(Order $order): void
    {
        $today = OrderFinancialSnapshot::query()->where('order_id', $order->id)->exists();

        if ($today) {
            throw new ApiException(
                'Order sudah memiliki snapshot keuangan; perubahan quantity/voucher/diskon/tambah baris tidak diizinkan.',
                422,
                ['code' => 'order_financial_snapshot_frozen'],
            );
        }
    }

    /**
     * Quantity adjustment window. The snapshot freezes ALLOCATIONS, but partial fulfilment may
     * legitimately reduce several lines, one after another, while the order is still being packed
     * (Package A/B invariant, covered by the existing sequential-reduction regressions). So a
     * shortfall-created snapshot stays adjustable while the order is still `diproses`; after the
     * order leaves that window (or if a handover/return/cancellation created the basis) quantity
     * changes are refused. Add-line and voucher changes always use assertMutable().
     */
    public function assertQuantityAdjustable(Order $order): void
    {
        $snapshot = OrderFinancialSnapshot::query()->where('order_id', $order->id)->first();

        if (! $snapshot) {
            return;
        }

        if ($snapshot->trigger === self::TRIGGER_FULFILLMENT_SHORTFALL && $order->status === 'diproses') {
            return;
        }

        throw new ApiException(
            'Order sudah memiliki snapshot keuangan; perubahan quantity/voucher/diskon/tambah baris tidak diizinkan.',
            422,
            ['code' => 'order_financial_snapshot_frozen'],
        );
    }

    /** Unconsumed frozen units for one line (0 when the whole line was already refunded once). */
    public function availableUnits(OrderFinancialSnapshot $snapshot, int $orderItemId): int
    {
        if ($snapshot->provenance === 'provisional_legacy') {
            return 0;
        }

        return (int) OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('order_item_id', $orderItemId)
            ->whereNull('consumed_at')
            ->count();
    }

    public function hasBillableUnits(Order $order): bool
    {
        return OrderItem::query()
            ->where('order_id', $order->id)
            ->where('fulfilled_quantity', '>', 0)
            ->exists();
    }

    public function exists(Order $order): bool
    {
        return OrderFinancialSnapshot::query()->where('order_id', $order->id)->exists();
    }

    /**
     * Consume N units of one line, ascending unit_index, once per unit ever.
     *
     * @return array{units: array<int, OrderFinancialSnapshotUnit>, reduction_cents: int, route: string}
     */
    public function consumeUnits(
        OrderFinancialSnapshot $snapshot,
        int $orderItemId,
        int $quantity,
        string $consumedByType,
        int $consumedById,
    ): array {
        if ($quantity < 1) {
            throw new ApiException('Jumlah unit yang dikonsumsi tidak valid.', 422, [
                'code' => 'invalid_snapshot_consumption_quantity',
            ]);
        }

        if ($snapshot->provenance === 'provisional_legacy') {
            // Unallocatable historical basis: consume nothing and let the caller create a
            // needs_verification obligation instead of an auto-refund.
            return ['units' => [], 'reduction_cents' => 0, 'route' => 'provisional_legacy'];
        }

        $units = OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('order_item_id', $orderItemId)
            ->whereNull('consumed_at')
            ->orderBy('unit_index')
            ->lockForUpdate()
            ->limit($quantity)
            ->get();

        if ($units->count() < $quantity) {
            throw new ApiException('Unit snapshot tidak mencukupi untuk konsumsi ini.', 409, [
                'code' => 'snapshot_units_exhausted',
            ]);
        }

        $reductionCents = 0;
        $sequence = (int) OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->max('consumption_seq');

        foreach ($units as $unit) {
            $affected = OrderFinancialSnapshotUnit::query()
                ->whereKey($unit->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                    'consumed_by_type' => $consumedByType,
                    'consumed_by_id' => $consumedById,
                    'consumption_seq' => ++$sequence,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException('Terjadi konflik konsumsi unit; silakan ulangi.', 409, [
                    'code' => 'snapshot_unit_consumption_conflict',
                ]);
            }

            $reductionCents += Money::cents($unit->unit_net);
        }

        $basis = OrderItemFinancialBasis::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('order_item_id', $orderItemId)
            ->lockForUpdate()
            ->first();

        if ($basis) {
            $consumedGross = 0;
            $consumedDiscount = 0;
            foreach ($units as $unit) {
                $consumedGross += Money::cents($unit->unit_gross);
                $consumedDiscount += Money::cents($unit->allocated_discount);
            }

            $basis->update([
                'consumed_units_count' => $basis->consumed_units_count + $units->count(),
                'consumed_gross_total' => Money::amount(Money::cents($basis->consumed_gross_total) + $consumedGross),
                'consumed_discount_total' => Money::amount(Money::cents($basis->consumed_discount_total) + $consumedDiscount),
                'consumed_net_total' => Money::amount(Money::cents($basis->consumed_net_total) + $reductionCents),
            ]);
        }

        return ['units' => $units->all(), 'reduction_cents' => $reductionCents, 'route' => 'frozen'];
    }

    /**
     * Revalidate the snapshot's stored checksum against its rows (defensive corruption check).
     */
    public function validateIntegrity(OrderFinancialSnapshot $snapshot): void
    {
        $units = OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('order_item_id')
            ->orderBy('unit_index')
            ->get()
            ->map(fn (OrderFinancialSnapshotUnit $unit) => [
                'order_item_id' => (int) $unit->order_item_id,
                'unit_index' => (int) $unit->unit_index,
                'unit_gross_cents' => Money::cents($unit->unit_gross),
            ])
            ->all();

        $allocated = OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('order_item_id')
            ->orderBy('unit_index')
            ->get()
            ->map(fn (OrderFinancialSnapshotUnit $unit) => Money::cents($unit->allocated_discount))
            ->all();

        $expected = $this->checksum(
            (int) $snapshot->order_id,
            Money::cents($snapshot->subtotal_basis),
            Money::cents($snapshot->effective_voucher_basis),
            $units,
            $allocated,
        );

        if (! hash_equals($snapshot->allocation_checksum, $expected)) {
            throw new ApiException('Snapshot checksum tidak cocok.', 409, [
                'code' => 'snapshot_checksum_mismatch',
            ]);
        }
    }

    /** @return array<int, array{order_item_id:int, quantity:int, unit_gross:float}> */
    private function captureLines(Order $order, ?array $unitOverride): array
    {
        if ($unitOverride !== null) {
            return $unitOverride;
        }

        $lines = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get() as $item) {
            $quantity = (int) $item->fulfilled_quantity;

            if ($quantity <= 0) {
                continue;
            }

            $lines[] = [
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'unit_gross' => round((float) $item->unit_price_snapshot, 2),
            ];
        }

        return $lines;
    }

    /**
     * Integer-cent largest-remainder allocation of V across unit gross values.
     * Ties break on lower order_item_id, then lower unit_index (input is already in that order).
     *
     * @param  array<int, array{order_item_id:int, unit_index:int, unit_gross_cents:int}>  $units
     * @return array<int, int> allocated cents per unit index
     */
    private function allocate(array $units, int $voucherCents): array
    {
        $totalGross = array_sum(array_column($units, 'unit_gross_cents'));

        if ($voucherCents <= 0 || $totalGross <= 0) {
            return array_fill(0, count($units), 0);
        }

        $allocated = [];
        $remainders = [];
        $assigned = 0;

        foreach ($units as $i => $unit) {
            $numerator = $voucherCents * $unit['unit_gross_cents'];
            $quota = intdiv($numerator, $totalGross);
            $remainder = $numerator % $totalGross;

            $allocated[$i] = min($quota, $unit['unit_gross_cents']);
            $remainders[$i] = $remainder;
            $assigned += $allocated[$i];
        }

        $left = $voucherCents - $assigned;

        while ($left > 0) {
            $best = null;
            $bestRemainder = -1;

            foreach ($units as $i => $unit) {
                if ($allocated[$i] >= $unit['unit_gross_cents']) {
                    continue;
                }

                if ($remainders[$i] > $bestRemainder) {
                    $best = $i;
                    $bestRemainder = $remainders[$i];
                }
            }

            if ($best === null) {
                throw new ApiException('Alokasi voucher gagal: tidak ada unit yang dapat menerima sisa.', 422, [
                    'code' => 'snapshot_allocation_impossible',
                ]);
            }

            $allocated[$best]++;
            $remainders[$best] = -1;
            $left--;
        }

        return $allocated;
    }

    /**
     * @param  array<int, array{order_item_id:int, unit_index:int, unit_gross_cents:int}>  $units
     * @param  array<int, int>  $allocated
     */
    private function checksum(int $orderId, int $subtotal, int $voucher, array $units, array $allocated): string
    {
        $triplets = [];

        foreach ($units as $i => $unit) {
            $triplets[] = [$unit['order_item_id'], $unit['unit_index'], $unit['unit_gross_cents'], $allocated[$i]];
        }

        return hash('sha256', json_encode([
            'order_id' => $orderId,
            'subtotal' => $subtotal,
            'voucher' => $voucher,
            'units' => $triplets,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Pre-commit validation (design §4.2 step 5). Any mismatch aborts the whole transaction.
     *
     * @param  array<int, array{order_item_id:int, unit_index:int, unit_gross_cents:int}>  $units
     * @param  array<int, int>  $allocated
     */
    private function validateOrFail(
        OrderFinancialSnapshot $snapshot,
        array $units,
        array $allocated,
        int $subtotalCents,
        int $voucherCents,
    ): void {
        $gross = array_sum(array_column($units, 'unit_gross_cents'));
        $discount = array_sum($allocated);

        if ($gross !== $subtotalCents || $discount !== $voucherCents) {
            throw new ApiException('Validasi snapshot gagal: total alokasi tidak konsisten.', 422, [
                'code' => 'snapshot_basis_inconsistent',
            ]);
        }

        $storedGross = Money::cents(OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)->sum('unit_gross'));
        $storedDiscount = Money::cents(OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)->sum('allocated_discount'));

        if ($storedGross !== $gross || $storedDiscount !== $discount) {
            throw new ApiException('Validasi snapshot gagal: baris tersimpan tidak konsisten.', 422, [
                'code' => 'snapshot_projection_inconsistent',
            ]);
        }
    }
}
