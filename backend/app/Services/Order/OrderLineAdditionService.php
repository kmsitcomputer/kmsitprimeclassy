<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Stock\StockRequestService;
use App\Services\Stock\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Package C / SC-03: add a NEW product/variation line to an existing order.
 *
 * This is NOT a quantity adjustment (that only ever changes an existing line's fulfilled_quantity).
 * It composes the EXISTING canonical services rather than re-implementing them:
 *  - OrderService::resolveLine + addReservedLine  -> snapshot + Agent reservation + commission
 *  - OrderFulfillmentService::assignFreshShipment  -> one fresh standard pending Shipment
 *  - StockRequestService::appendItemForOrderItem   -> reconcile the one-per-order request
 *  - OrderTotalCalculator + PaymentService          -> canonical order-level totals/payment truth
 *
 * Authority is enforced upstream (OrderPolicy::addLine + the dedicated role:admin route group):
 * exactly role `admin`, same Agent branch.
 *
 * Lock order (canonical): Order row -> Stock Request -> Agent inventory target(s)
 * (StockService::canonicalReservationTargets) -> Stock Request items -> new OrderItem/Shipment.
 * Stock Request precedes inventory so it matches warehouse proposal/approval (REV-003).
 */
class OrderLineAdditionService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderFulfillmentService $fulfillmentService,
        private readonly OrderTotalCalculator $orderTotalCalculator,
        private readonly StockService $stockService,
        private readonly StockRequestService $stockRequestService,
    ) {}

    /**
     * @param  array{product_id:int, product_variation_id?:?int, quantity:int}  $line
     * @return array{0: OrderItem, 1: bool}  [created/loaded item, wasReplay]
     */
    public function addLine(
        Order $order,
        array $line,
        User $actor,
        ?string $requestedDeliveryDate,
        string $reason,
        string $additionalPaymentMethod,
        string $idempotencyKey,
    ): array {
        $fingerprint = self::requestFingerprint($line, $requestedDeliveryDate, $additionalPaymentMethod);

        try {
            return DB::transaction(function () use ($order, $line, $actor, $requestedDeliveryDate, $reason, $additionalPaymentMethod, $idempotencyKey, $fingerprint) {
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                // Idempotency: a replay of the same ORIGINAL logical request returns the already-created
                // line (no second item/reservation/shipment/stock-request/commission/obligation). It is
                // resolved BEFORE any creation-only validation (status window, requested-date-not-past,
                // stock) so a legitimate retry stays valid after the line was adjusted/split, the order
                // moved on, or the requested date elapsed. The same key reused for a DIFFERENT request
                // is a conflict, never a silent no-op.
                $existing = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    $this->assertSameRequest($existing, $fingerprint, $line);

                    return [$existing, true];
                }

                if ($order->status !== 'diproses') {
                    throw new ApiException(__('messages.fulfillment.window_closed'), 422);
                }

                if ($requestedDeliveryDate !== null && Carbon::parse($requestedDeliveryDate)->startOfDay()->lt(today())) {
                    throw new ApiException(
                        __('messages.order.line_addition_date_in_past'), 422, ['requested_delivery_date' => __('messages.order.line_addition_date_in_past')]
                    );
                }

                // SC-03 is Admin-only and therefore Agent-stock only; a Sub-sourced order is out of
                // scope (a mixed-source order would be a new capability, not part of Package C).
                if (OrderItem::query()->where('order_id', $order->id)->where('stock_source', 'sub')->exists()) {
                    throw new ApiException(__('messages.order.line_addition_sub_not_supported'), 422);
                }

                // Server-authoritative product/variation resolution (single shared resolver).
                $resolved = $this->orderService->resolveLine($line);
                $target = [
                    'product_id' => $resolved['product']->id,
                    'product_variation_id' => $resolved['variation']?->id,
                ];

                // Stock Request BEFORE inventory: the warehouse proposal/approval path locks the Stock
                // Request and only then the Transit/Shipping/Agent rows, so taking the request first here
                // gives both paths one order and removes the SC-03 <-> approval deadlock (REV-003).
                $this->stockRequestService->lockActiveRequestForOrder($order);

                // Canonical Agent capacity prelock, before any per-line reserve (same vocabulary as
                // checkout and Transit -> Sub execution, so no lock inversion is introduced).
                $this->stockService->lockReservationTargets($order->agent_id, [$target]);

                $konsumen = User::query()->findOrFail($order->konsumen_id);
                $totalBefore = (float) $order->total_amount;
                $previousRemaining = max(0.0, $totalBefore - (float) $order->paid_amount);

                // Snapshot + reserve + commission — the canonical checkout line path.
                $item = $this->orderService->addReservedLine($order, $konsumen, $actor, $resolved, $requestedDeliveryDate, $idempotencyKey);
                // Immutable original-request identity, written once with the line and never updated.
                $item->forceFill(['request_fingerprint' => $fingerprint])->save();

                // One fresh standard pending Shipment for the new line (reuses the canonical helper).
                $this->fulfillmentService->assignFreshShipment($item, $order, $actor, 'shipment.added_for_line');

                // Reconcile the order's existing Stock Request with the new demand.
                $this->stockRequestService->appendItemForOrderItem($order, $item);

                // Order-level totals, then canonical financial reconciliation (never marks paid; only
                // creates the additional-payment obligation when the order was already settled).
                $order = $this->orderTotalCalculator->recalculate($order);
                $this->fulfillmentService->reconcileAdditionalObligation($order, $item, $previousRemaining, $additionalPaymentMethod, $reason, $actor);

                ActivityLogger::log($actor->id, $item, 'order_item.added', $reason, [
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_variation_id' => $item->product_variation_id,
                    'quantity' => $item->original_quantity,
                    'stock_source' => $item->stock_source,
                    'requested_delivery_date' => $item->requested_delivery_date?->toDateString(),
                    'idempotency_key' => $idempotencyKey,
                    'total_before' => $totalBefore,
                    'total_after' => (float) $order->total_amount,
                    'actor_role' => $actor->role?->slug,
                ]);

                return [$item->fresh(), false];
            });
        } catch (QueryException $e) {
            // Concurrency backstop: two identical submissions with the same key — the unique
            // (order_id, idempotency_key) index rejected the loser after its transaction rolled back
            // (its reservation is undone). Return the winner's line instead of a 500.
            if (str_contains($e->getMessage(), 'order_items_order_id_idempotency_key_unique')) {
                $item = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
                $this->assertSameRequest($item, $fingerprint, $line);

                return [$item, true];
            }

            throw $e;
        }
    }

    /**
     * Identity of the ORIGINAL logical request: every input that can alter the business outcome, in
     * canonical normalized form (nullable variation as null, quantity as int, requested date as
     * Y-m-d or null when the client left it to the order default, payment method as submitted-or-
     * default). `reason` is deliberately EXCLUDED: it is an audit annotation recorded once on the
     * first commit and does not change stock, money, schedule or fulfillment, so a retry that merely
     * rewords it is the same logical request.
     */
    public static function requestFingerprint(array $line, ?string $requestedDeliveryDate, string $additionalPaymentMethod): string
    {
        return hash('sha256', json_encode([
            'product_id' => (int) $line['product_id'],
            'product_variation_id' => ! empty($line['product_variation_id']) ? (int) $line['product_variation_id'] : null,
            'quantity' => (int) $line['quantity'],
            'requested_delivery_date' => $requestedDeliveryDate !== null ? Carbon::parse($requestedDeliveryDate)->toDateString() : null,
            'additional_payment_method' => $additionalPaymentMethod,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertSameRequest(OrderItem $existing, string $fingerprint, array $line): void
    {
        // Rows written before the fingerprint existed (DEV-only, never in production) fall back to
        // the product/variation/quantity identity they carried.
        $same = $existing->request_fingerprint !== null
            ? hash_equals($existing->request_fingerprint, $fingerprint)
            : ((int) $existing->product_id === (int) $line['product_id']
                && (int) ($existing->product_variation_id ?? 0) === (int) ($line['product_variation_id'] ?? 0)
                && (int) $existing->original_quantity === (int) $line['quantity']);

        if (! $same) {
            throw new ApiException(__('messages.order.line_idempotency_conflict'), 409);
        }
    }
}
