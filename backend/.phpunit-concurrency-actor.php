<?php

use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Shipment;
use App\Models\StockOpname;
use App\Models\StockRequest;
use App\Models\StockRequestProposal;
use App\Models\StockTransfer;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderService;
use App\Services\Stock\StockOpnameService;
use App\Services\Stock\StockRequestFulfillmentService;
use App\Services\Stock\StockRequestProposalService;
use App\Services\Stock\StockService;
use App\Services\Stock\StockTransferService;
use App\Services\Stock\SubStockRequestService;
use App\Services\Stock\SubStockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$longOptions = [
    'role:',
    'runtime-dir:',
    'actor-a-ready:',
    'actor-b-attempt:',
    'release-file:',
    'actor-a-result:',
    'actor-b-result:',
    'checkout-ready-a:',
    'checkout-ready-b:',
    'checkout-release:',
    'checkout-result-a:',
    'checkout-result-b:',
    'checkout-buyer-a:',
    'checkout-buyer-b:',
    'checkout-product:',
    'checkout-agent:',
    'checkout-quantity:',
    'checkout-destination:',
    'fulfillment-ready-a:',
    'fulfillment-ready-b:',
    'fulfillment-release:',
    'fulfillment-result-a:',
    'fulfillment-result-b:',
    'fulfillment-actor-a:',
    'fulfillment-actor-b:',
    'fulfillment-request:',
    'fulfillment-item:',
    'fulfillment-quantity:',
    'fulfillment-proposal:',
    'fc-ready-a:',
    'fc-ready-b:',
    'fc-release:',
    'fc-result-a:',
    'fc-result-b:',
    'fc-fulfillment-actor:',
    'fc-cancellation-actor:',
    'fc-order:',
    'fc-request:',
    'fc-item:',
    'fc-quantity:',
    'sr-ready-a:',
    'sr-ready-b:',
    'sr-release:',
    'sr-result-a:',
    'sr-result-b:',
    'sr-op:',
    'sr-actor:',
    'sr-subject:',
    'sr-extra:',
    'fc-proposal:',
    'to-ready-a:',
    'to-ready-b:',
    'to-release:',
    'to-result-a:',
    'to-result-b:',
    'to-transfer-actor:',
    'to-opname-actor:',
    'to-transfer:',
    'to-opname:',
    'order-race-locked:',
    'order-race-observed:',
    'order-race-release:',
    'order-race-writer-result:',
    'order-race-service-result:',
    'order-race-order:',
    'order-race-actor:',
    'order-race-operation:',
];

$options = getopt('', $longOptions);

if (! isset($options['role'], $options['runtime-dir'])) {
    fwrite(STDERR, "Missing required process options.\n");
    exit(2);
}

$role = $options['role'];
$runtimeDir = trim((string) $options['runtime-dir'], " \t\n\r\0\x0B\"'");
$actorAReady = trim((string) ($options['actor-a-ready'] ?? ''), " \t\n\r\0\x0B\"'");
$actorBAttempt = trim((string) ($options['actor-b-attempt'] ?? ''), " \t\n\r\0\x0B\"'");
$releaseFile = trim((string) ($options['release-file'] ?? ''), " \t\n\r\0\x0B\"'");
$actorAResult = trim((string) ($options['actor-a-result'] ?? ''), " \t\n\r\0\x0B\"'");
$actorBResult = trim((string) ($options['actor-b-result'] ?? ''), " \t\n\r\0\x0B\"'");

if (! is_dir($runtimeDir)) {
    mkdir($runtimeDir, 0775, true);
}

$autoload = __DIR__.'/vendor/autoload.php';
if (! file_exists($autoload)) {
    fwrite(STDERR, "Autoload not found.\n");
    exit(2);
}

require $autoload;

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;

if (str_starts_with($role, 'order-race-')) {
    $locked = (string) $options['order-race-locked'];
    $observed = (string) $options['order-race-observed'];
    $release = (string) $options['order-race-release'];
    $writerResult = (string) $options['order-race-writer-result'];
    $serviceResult = (string) $options['order-race-service-result'];
    $orderId = (int) $options['order-race-order'];
    $actorId = (int) $options['order-race-actor'];
    $operation = (string) $options['order-race-operation'];

    if ($role === 'order-race-writer') {
        DB::beginTransaction();
        $order = Order::withoutGlobalScopes()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $committedStatus = $operation === 'cancel' ? 'dikirim' : 'diproses';
        $order->update(['status' => $committedStatus]);
        @file_put_contents($locked, 'locked');
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && ! file_exists($release)) {
            usleep(100000);
        }
        DB::commit();
        @file_put_contents($writerResult, json_encode([
            'connection_id' => $connectionId, 'committed_status' => $committedStatus, 'success' => true,
        ]));
        exit(0);
    }

    $payload = ['connection_id' => $connectionId, 'outcome' => 'failed'];
    try {
        $order = Order::withoutGlobalScopes()->with(['items', 'paymentMethod'])->findOrFail($orderId);
        $payload['observed_status'] = $order->status;
        @file_put_contents($observed, 'observed');
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        if ($operation === 'cancel') {
            app(OrderService::class)->cancel($order, $actor, 'locked-state race');
        } else {
            app(OrderService::class)->updateStatus($order, 'diproses', $actor);
        }
        $payload['outcome'] = 'success';
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['outcome'] = 'rejected';
    }
    @file_put_contents($serviceResult, json_encode($payload));
    exit(0);
}

if (str_starts_with($role, 'checkout-')) {
    $readyA = trim((string) ($options['checkout-ready-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $readyB = trim((string) ($options['checkout-ready-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $release = trim((string) ($options['checkout-release'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultA = trim((string) ($options['checkout-result-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultB = trim((string) ($options['checkout-result-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $isA = $role === 'checkout-actor-a';
    $ready = $isA ? $readyA : $readyB;
    $resultPath = $isA ? $resultA : $resultB;
    $buyerId = (int) ($isA ? $options['checkout-buyer-a'] : $options['checkout-buyer-b']);
    $destination = json_decode(base64_decode((string) $options['checkout-destination'], true), true, 512, JSON_THROW_ON_ERROR);
    $startedAt = microtime(true);

    @file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($release)) {
        usleep(100000);
    }

    $payload = [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'outcome' => 'failed',
        'exit_code' => 1,
    ];

    try {
        $buyer = User::withoutGlobalScopes()->findOrFail($buyerId);
        $order = app(OrderService::class)->createOrder(
            $buyer,
            [['product_id' => (int) $options['checkout-product'], 'quantity' => (int) $options['checkout-quantity']]],
            $destination,
            $buyer,
            'cod',
            null,
            'concurrency-'.bin2hex(random_bytes(8)),
            null,
            null,
            null,
        );
        $payload['outcome'] = 'success';
        $payload['exit_code'] = 0;
        $payload['order_id'] = $order->id;
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['sql_state'] = $e instanceof QueryException ? $e->errorInfo[0] ?? null : null;
        $payload['error_code'] = $e instanceof QueryException ? $e->errorInfo[1] ?? null : null;
    }

    $payload['completed_at'] = microtime(true);
    @file_put_contents($resultPath, json_encode($payload));
    exit($payload['exit_code']);
}

if (str_starts_with($role, 'fulfillment-')) {
    $readyA = trim((string) ($options['fulfillment-ready-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $readyB = trim((string) ($options['fulfillment-ready-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $release = trim((string) ($options['fulfillment-release'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultA = trim((string) ($options['fulfillment-result-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultB = trim((string) ($options['fulfillment-result-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $isA = $role === 'fulfillment-actor-a';
    $ready = $isA ? $readyA : $readyB;
    $resultPath = $isA ? $resultA : $resultB;
    $actorId = (int) ($isA ? $options['fulfillment-actor-a'] : $options['fulfillment-actor-b']);
    $startedAt = microtime(true);

    @file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($release)) {
        usleep(100000);
    }

    $payload = [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'requested_quantity' => (int) $options['fulfillment-quantity'],
        'outcome' => 'failed',
        'exit_code' => 1,
    ];

    try {
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        if (isset($options['fulfillment-proposal'])) {
            // Direct Gudang fulfillment is closed: physical stock only moves when an
            // Admin approves a proposal, so the race is between concurrent approvals.
            $proposal = StockRequestProposal::withoutGlobalScopes()->findOrFail((int) $options['fulfillment-proposal']);
            $result = app(StockRequestProposalService::class)->approve($actor, $proposal);
            $payload['outcome'] = 'success';
            $payload['exit_code'] = 0;
            $payload['proposal_status'] = $result->status;
        } else {
            $request = StockRequest::withoutGlobalScopes()->findOrFail((int) $options['fulfillment-request']);
            $result = app(StockRequestFulfillmentService::class)->fulfill(
                $actor,
                $request,
                [['item_id' => (int) $options['fulfillment-item'], 'quantity' => (int) $options['fulfillment-quantity']]],
                'concurrency-'.bin2hex(random_bytes(8)),
            );
            $payload['outcome'] = 'success';
            $payload['exit_code'] = 0;
            $payload['request_status'] = $result->status;
        }
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['sql_state'] = $e instanceof QueryException ? $e->errorInfo[0] ?? null : null;
        $payload['error_code'] = $e instanceof QueryException ? $e->errorInfo[1] ?? null : null;
    }

    $payload['completed_at'] = microtime(true);
    @file_put_contents($resultPath, json_encode($payload));
    exit($payload['exit_code']);
}

if (str_starts_with($role, 'sr-')) {
    $isA = $role === 'sr-a';
    $ready = trim((string) ($options[$isA ? 'sr-ready-a' : 'sr-ready-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $release = trim((string) ($options['sr-release'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultPath = trim((string) ($options[$isA ? 'sr-result-a' : 'sr-result-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $operation = (string) ($options['sr-op'] ?? '');
    $actorId = (int) ($options['sr-actor'] ?? 0);
    $subjectId = (int) ($options['sr-subject'] ?? 0);
    $extra = isset($options['sr-extra']) ? (json_decode(base64_decode((string) $options['sr-extra']), true) ?? []) : [];
    $startedAt = microtime(true);

    @file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($release)) {
        usleep(100000);
    }

    $payload = [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'operation' => $operation,
        'outcome' => 'failed',
        'exit_code' => 1,
    ];

    try {
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        switch ($operation) {
            case 'transfer-approve':
                $result = app(StockTransferService::class)->approve($actor, StockTransfer::withoutGlobalScopes()->findOrFail($subjectId));
                break;
            case 'transfer-cancel':
                $result = app(StockTransferService::class)->cancel($actor, StockTransfer::withoutGlobalScopes()->findOrFail($subjectId));
                break;
            case 'opname-approve':
                $result = app(StockOpnameService::class)->approve($actor, StockOpname::withoutGlobalScopes()->findOrFail($subjectId));
                break;
            case 'opname-reject':
                $result = app(StockOpnameService::class)->reject($actor, StockOpname::withoutGlobalScopes()->findOrFail($subjectId), 'concurrency rejection');
                break;
            case 'sub-reserve':
                $item = OrderItem::findOrFail($subjectId);
                $result = DB::transaction(fn () => app(SubStockService::class)->reserve($item, (int) $item->sub_location_id, (int) Order::withoutGlobalScopes()->whereKey($item->order_id)->value('agent_id'), (int) $item->original_quantity, $actor));
                break;
            case 'sub-request-approve':
                $result = app(SubStockRequestService::class)->approve($actor, SubStockRequest::withoutGlobalScopes()->findOrFail($subjectId));
                break;
            case 'sub-request-execute':
                $result = app(SubStockRequestService::class)->execute($actor, SubStockRequest::withoutGlobalScopes()->findOrFail($subjectId));
                break;
            case 'sub-consume':
                $item = OrderItem::findOrFail($subjectId);
                $result = DB::transaction(fn () => app(SubStockService::class)->consume($item, $actor));
                break;
            case 'sub-increase':
                // R-03: concurrent pre-shipment Sub reservation increases must serialize on the
                // Sub stock row so free sellable capacity is never oversold.
                $item = OrderItem::findOrFail($subjectId);
                $result = DB::transaction(fn () => app(SubStockService::class)->increase($item, (int) ($extra['quantity'] ?? 0), $actor, 'concurrency-test'));
                break;
            case 'sub-reduce':
                // R-03: concurrent pre-shipment Sub reservation reductions must serialize on the
                // Sub stock row so the reservation can never be reduced below zero.
                $item = OrderItem::findOrFail($subjectId);
                $result = DB::transaction(fn () => app(SubStockService::class)->reduce($item, (int) ($extra['quantity'] ?? 0), $actor, 'concurrency-test'));
                break;
            case 'delivery-verify':
                // R-03 / MAJOR-4: concurrent exact replays of one delivery verification must yield
                // exactly one append-only row (unique index + replay match), never a 500/duplicate.
                $shipment = Shipment::withoutGlobalScopes()->findOrFail($subjectId);
                $result = app(\App\Services\Order\DeliveryVerificationService::class)->record(
                    $shipment, $actor,
                    (string) ($extra['outcome'] ?? 'received'),
                    $extra['note'] ?? null,
                    (string) ($extra['key'] ?? 'race-verify-key'),
                );
                break;
            case 'fulfillment-increase':
                // R-03 / MAJOR-10: admin quantity adjustment racing a Keuangan financial settlement
                // must not deadlock. `quantity` is the TARGET fulfilled quantity.
                $item = OrderItem::findOrFail($subjectId);
                $result = app(OrderFulfillmentService::class)->adjustItemQuantity($item, (int) ($extra['quantity'] ?? 0), $actor, 'concurrency-test', (string) ($extra['method'] ?? 'cod'));
                break;
            case 'add-line':
                // Package C / SC-03: the REAL existing-order line-addition path, used to race two
                // additions (same key -> one line; distinct keys -> both) and an addition against a
                // concurrent Agent reservation on the same target (capacity).
                $order = Order::withoutGlobalScopes()->findOrFail($subjectId);
                [$createdItem, $wasReplay] = app(\App\Services\Order\OrderLineAdditionService::class)->addLine(
                    $order,
                    $extra['line'],
                    $actor,
                    $extra['date'] ?? null,
                    (string) ($extra['reason'] ?? 'concurrency-test'),
                    (string) ($extra['method'] ?? 'cod'),
                    (string) $extra['key'],
                );
                $payload['replayed'] = $wasReplay;
                $payload['order_item_id'] = $createdItem->id;
                $result = $createdItem;
                break;
            case 'fulfillment-reduce':
                $item = OrderItem::findOrFail($subjectId);
                $result = app(OrderFulfillmentService::class)->adjustItemQuantity($item, (int) ($extra['quantity'] ?? 0), $actor, 'concurrency-test');
                break;
            case 'refund-process':
                $adjustment = OrderItemAdjustment::query()->findOrFail($subjectId);
                $result = app(OrderFulfillmentService::class)->markAdjustmentRefundStatus($adjustment, $actor, 'processed');
                break;
            case 'additional-settle':
                $payment = OrderAdditionalPayment::query()->findOrFail($subjectId);
                $result = app(OrderFulfillmentService::class)->markAdditionalPaymentPaid($payment, $actor, (bool) ($extra['paid'] ?? true));
                break;
            case 'sub-location-assign-owner':
                // MAJOR-2 remediation: concurrent owner assignment vs. generic transfer approval
                // must serialize on the same WarehouseSubLocation row lock (see
                // StockTransferService::assertNoOwnedSubLocation's locked branch).
                $result = app(\App\Services\Stock\SubLocationOwnershipService::class)->assignOwner(
                    $actor,
                    \App\Models\WarehouseSubLocation::withoutGlobalScopes()->findOrFail((int) $extra['location_id']),
                    (int) $extra['owner_user_id'],
                );
                break;
            case 'agent-reserve':
                // MAJOR-1 remediation: an Agent checkout reservation must serialize against a
                // concurrent Transit -> Sub execution through the same canonical lock order
                // (see StockTransferService::lockAgentCapacityForTransitToSub).
                $agentId = (int) $extra['agent_id'];
                $quantity = (int) $extra['quantity'];
                $result = DB::transaction(function () use ($agentId, $quantity, $extra, $actorId) {
                    if (! empty($extra['variation_id'])) {
                        app(StockService::class)->reserveForVariation($agentId, ProductVariation::findOrFail((int) $extra['variation_id']), $quantity, 'concurrency-test', 0, $actorId);
                    } else {
                        app(StockService::class)->reserveForProduct($agentId, Product::findOrFail((int) $extra['product_id']), $quantity, 'concurrency-test', 0, $actorId);
                    }

                    return null;
                });
                break;
            case 'agent-lock-multi':
                // Finding 1: exercises ONLY the production per-target lock sequence used by every
                // multi-target inventory transaction (StockService::lockReservationTarget). With
                // `canonicalize` false the caller-supplied order is used verbatim; with true the
                // production canonicalReservationTargets() order is used. The optional file barrier
                // pauses each side after its FIRST target so an inverted order deterministically
                // deadlocks, proving the ordering contract is real.
                $agentId = (int) $extra['agent_id'];
                $targets = $extra['targets'];
                $canonicalize = (bool) ($extra['canonicalize'] ?? true);
                $barrierDir = $extra['barrier_dir'] ?? null;
                $side = (string) ($extra['side'] ?? 'a');
                $peerFirstKey = $extra['peer_first_key'] ?? null;
                $result = DB::transaction(function () use ($agentId, $targets, $canonicalize, $barrierDir, $side, $peerFirstKey) {
                    $normalized = [];
                    foreach ($targets as $t) {
                        $normalized[] = [
                            'product_id' => isset($t['product_id']) ? (int) $t['product_id'] : null,
                            'product_variation_id' => ! empty($t['variation_id']) ? (int) $t['variation_id'] : (! empty($t['product_variation_id']) ? (int) $t['product_variation_id'] : null),
                        ];
                    }
                    $ordered = $canonicalize ? StockService::canonicalReservationTargets($normalized) : $normalized;
                    $stock = app(StockService::class);
                    $lock = fn (array $t) => $stock->lockReservationTarget($agentId, $t['product_id'], $t['product_variation_id']);
                    $first = array_shift($ordered);
                    $firstKey = StockService::canonicalTargetKey($first['product_id'], $first['product_variation_id']);
                    $lock($first);
                    if ($barrierDir !== null && $peerFirstKey !== null && $peerFirstKey !== $firstKey) {
                        @file_put_contents($barrierDir.'/'.$side.'.first', '1');
                        $peer = $barrierDir.'/'.($side === 'a' ? 'b' : 'a').'.first';
                        $deadline = microtime(true) + 20;
                        while (! file_exists($peer) && microtime(true) < $deadline) {
                            usleep(20000);
                        }
                    }
                    foreach ($ordered as $t) {
                        $lock($t);
                    }

                    return null;
                });
                break;
            case 'checkout-order':
                // Finding 1: the REAL multi-line checkout path (OrderService::createOrder) so a
                // reversed-line order genuinely exercises the pre-lock canonicalisation, racing a
                // real Transit -> Sub execution in the sibling op. `stock_source`/`sub_location_id`
                // drive the Sub-sourced MAJOR C race through the same real path.
                $buyer = User::withoutGlobalScopes()->findOrFail((int) $extra['buyer_id']);
                $result = app(OrderService::class)->createOrder(
                    $buyer,
                    $extra['lines'],
                    $extra['destination'],
                    $buyer,
                    'cod',
                    null,
                    'concurrency-'.bin2hex(random_bytes(8)),
                    null,
                    null,
                    null,
                    $extra['stock_source'] ?? null,
                    isset($extra['sub_location_id']) ? (int) $extra['sub_location_id'] : null,
                );
                break;
            case 'cancel-order':
                // MAJOR B: the REAL cancellation reversal path, racing a real Transit -> Sub
                // execution. Its Agent capacity prelock must be canonical.
                $actor = User::withoutGlobalScopes()->findOrFail($actorId);
                $order = Order::withoutGlobalScopes()->findOrFail((int) $extra['order_id']);
                $result = app(OrderService::class)->cancel($order, $actor, (string) ($extra['reason'] ?? 'concurrency cancellation'));
                break;
            case 'sub-reserve-order':
                // MAJOR C: the real multi-target Sub reservation sequence OrderService uses — the
                // production canonical prelock (SubStockService::lockReservationTargets) followed by
                // the production per-item reserve() in the caller's line order. `canonicalize` false
                // skips the prelock (the pre-fix behaviour) and is used as a positive control.
                $subLocationId = (int) $extra['sub_location_id'];
                $itemIds = array_map('intval', $extra['order_item_ids']);
                $canonicalize = (bool) ($extra['canonicalize'] ?? true);
                $barrierDir = $extra['barrier_dir'] ?? null;
                $side = (string) ($extra['side'] ?? 'a');
                $peerFirstKey = $extra['peer_first_key'] ?? null;
                $actor = User::withoutGlobalScopes()->findOrFail($actorId);
                $result = DB::transaction(function () use ($subLocationId, $itemIds, $canonicalize, $barrierDir, $side, $peerFirstKey, $actor) {
                    $items = OrderItem::whereIn('id', $itemIds)->get()->keyBy('id');
                    $ordered = [];
                    foreach ($itemIds as $id) {
                        $ordered[] = $items[$id];
                    }
                    $subStock = app(SubStockService::class);
                    if ($canonicalize) {
                        $subStock->lockReservationTargets($subLocationId, array_map(fn ($item) => [
                            'product_id' => $item->product_variation_id ? null : $item->product_id,
                            'product_variation_id' => $item->product_variation_id,
                        ], $ordered));
                    }
                    $firstKey = StockService::canonicalTargetKey($ordered[0]->product_variation_id ? null : $ordered[0]->product_id, $ordered[0]->product_variation_id);
                    foreach ($ordered as $index => $item) {
                        $agentId = (int) Order::withoutGlobalScopes()->whereKey($item->order_id)->value('agent_id');
                        $subStock->reserve($item, $subLocationId, $agentId, (int) $item->original_quantity, $actor);

                        // Positive-control barrier: only when the prelock is skipped and the two sides
                        // start on DIFFERENT targets, pause after the first reserve so each holds one
                        // Sub row before either attempts its second (a guaranteed cycle).
                        if ($index === 0 && ! $canonicalize && $barrierDir !== null && $peerFirstKey !== null && $peerFirstKey !== $firstKey) {
                            @file_put_contents($barrierDir.'/'.$side.'.first', '1');
                            $peer = $barrierDir.'/'.($side === 'a' ? 'b' : 'a').'.first';
                            $deadline = microtime(true) + 20;
                            while (! file_exists($peer) && microtime(true) < $deadline) {
                                usleep(20000);
                            }
                        }
                    }

                    return null;
                });
                break;
            case 'sub-location-stock-lock':
                // MAJOR C (final): models the two Sub-domain lock orders for a deterministic
                // cross-transaction test. `location_first` true is the canonical order (location
                // shared/exclusive, then stock targets); false is the pre-fix checkout order (stock
                // targets, then the parent row at the order_item FK insert). The optional barrier pauses
                // each side after its FIRST lock, so an inverted order deterministically deadlocks.
                $locationId = (int) $extra['location_id'];
                $targets = $extra['targets'] ?? [];
                $locationMode = (string) ($extra['location_mode'] ?? 'none'); // none|share|exclusive
                $locationFirst = (bool) ($extra['location_first'] ?? true);
                $canonicalize = (bool) ($extra['canonicalize'] ?? true);
                $barrierDir = $extra['barrier_dir'] ?? null;
                $side = (string) ($extra['side'] ?? 'a');
                $peerFirstKey = $extra['peer_first_key'] ?? null;
                $result = DB::transaction(function () use ($locationId, $targets, $locationMode, $locationFirst, $canonicalize, $barrierDir, $side, $peerFirstKey) {
                    $normalized = [];
                    foreach ($targets as $t) {
                        $normalized[] = [
                            'product_id' => isset($t['product_id']) ? (int) $t['product_id'] : null,
                            'product_variation_id' => ! empty($t['variation_id']) ? (int) $t['variation_id'] : (! empty($t['product_variation_id']) ? (int) $t['product_variation_id'] : null),
                        ];
                    }
                    $ordered = $canonicalize ? StockService::canonicalReservationTargets($normalized) : $normalized;
                    $subStock = app(SubStockService::class);
                    $lockStock = fn (array $t) => $subStock->lockReservationTargets($locationId, [$t]);
                    $lockLocation = function () use ($locationId, $locationMode) {
                        $query = \App\Models\WarehouseSubLocation::withoutGlobalScopes()->whereKey($locationId);
                        $locationMode === 'share' ? $query->sharedLock() : $query->lockForUpdate();

                        return $query->first();
                    };
                    if ($locationFirst && $locationMode !== 'none') {
                        $lockLocation();
                        $firstKey = 'loc:'.$locationId;
                    } else {
                        $first = array_shift($ordered);
                        $firstKey = StockService::canonicalTargetKey($first['product_id'], $first['product_variation_id']);
                        $lockStock($first);
                    }
                    if ($barrierDir !== null && $peerFirstKey !== null && $peerFirstKey !== $firstKey) {
                        @file_put_contents($barrierDir.'/'.$side.'.first', '1');
                        $peer = $barrierDir.'/'.($side === 'a' ? 'b' : 'a').'.first';
                        $deadline = microtime(true) + 20;
                        while (! file_exists($peer) && microtime(true) < $deadline) {
                            usleep(20000);
                        }
                    }
                    foreach ($ordered as $t) {
                        $lockStock($t);
                    }
                    if (! $locationFirst && $locationMode !== 'none') {
                        $lockLocation();
                    }

                    return null;
                });
                break;
            default:
                throw new InvalidArgumentException('Unknown service race operation: '.$operation);
        }
        $payload['final_status'] = $result?->status;
        $payload['outcome'] = 'success';
        $payload['exit_code'] = 0;
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['sql_state'] = $e instanceof QueryException ? $e->errorInfo[0] ?? null : null;
        $payload['error_code'] = $e instanceof QueryException ? $e->errorInfo[1] ?? null : null;
    }

    $payload['completed_at'] = microtime(true);
    @file_put_contents($resultPath, json_encode($payload));
    exit($payload['exit_code']);
}

if (str_starts_with($role, 'fc-')) {
    $readyA = trim((string) ($options['fc-ready-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $readyB = trim((string) ($options['fc-ready-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $release = trim((string) ($options['fc-release'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultA = trim((string) ($options['fc-result-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultB = trim((string) ($options['fc-result-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $isFulfillment = $role === 'fc-fulfillment';
    $ready = $isFulfillment ? $readyA : $readyB;
    $resultPath = $isFulfillment ? $resultA : $resultB;
    $actorId = (int) ($isFulfillment ? $options['fc-fulfillment-actor'] : $options['fc-cancellation-actor']);
    $startedAt = microtime(true);

    @file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($release)) {
        usleep(100000);
    }

    $payload = [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'outcome' => 'failed',
        'exit_code' => 1,
    ];

    try {
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        if ($isFulfillment && isset($options['fc-proposal'])) {
            // Direct Gudang fulfillment is closed: the competing fulfillment is an
            // Admin approving a pending proposal.
            $proposal = StockRequestProposal::withoutGlobalScopes()->findOrFail((int) $options['fc-proposal']);
            $result = app(StockRequestProposalService::class)->approve($actor, $proposal);
            $payload['proposal_status'] = $result->status;
            $payload['outcome'] = 'success';
            $payload['exit_code'] = 0;
        } elseif ($isFulfillment) {
            $request = StockRequest::withoutGlobalScopes()->findOrFail((int) $options['fc-request']);
            $result = app(StockRequestFulfillmentService::class)->fulfill(
                $actor,
                $request,
                [['item_id' => (int) $options['fc-item'], 'quantity' => (int) $options['fc-quantity']]],
                'concurrency-'.bin2hex(random_bytes(8)),
            );
            $payload['request_status'] = $result->status;
            $payload['outcome'] = 'success';
            $payload['exit_code'] = 0;
        } else {
            $order = Order::withoutGlobalScopes()->with(['items', 'paymentMethod'])->findOrFail((int) $options['fc-order']);
            $result = app(OrderService::class)->cancel($order, $actor, 'concurrency cancellation test');
            $payload['order_status'] = $result->status;
            $payload['outcome'] = 'success';
            $payload['exit_code'] = 0;
        }
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['sql_state'] = $e instanceof QueryException ? $e->errorInfo[0] ?? null : null;
        $payload['error_code'] = $e instanceof QueryException ? $e->errorInfo[1] ?? null : null;
    }

    $payload['completed_at'] = microtime(true);
    @file_put_contents($resultPath, json_encode($payload));
    exit($payload['exit_code']);
}

if (str_starts_with($role, 'to-')) {
    $readyA = trim((string) ($options['to-ready-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $readyB = trim((string) ($options['to-ready-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $release = trim((string) ($options['to-release'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultA = trim((string) ($options['to-result-a'] ?? ''), " \t\n\r\0\x0B\"'");
    $resultB = trim((string) ($options['to-result-b'] ?? ''), " \t\n\r\0\x0B\"'");
    $isTransfer = $role === 'to-transfer';
    $ready = $isTransfer ? $readyA : $readyB;
    $resultPath = $isTransfer ? $resultA : $resultB;
    $actorId = (int) ($isTransfer ? $options['to-transfer-actor'] : $options['to-opname-actor']);
    $startedAt = microtime(true);

    @file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($release)) {
        usleep(100000);
    }

    $payload = [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'outcome' => 'failed',
        'exit_code' => 1,
    ];

    try {
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        if ($isTransfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->findOrFail((int) $options['to-transfer']);
            $result = app(StockTransferService::class)->approve($actor, $transfer);
            $payload['transfer_status'] = $result->status;
        } else {
            $opname = StockOpname::withoutGlobalScopes()->findOrFail((int) $options['to-opname']);
            $result = app(StockOpnameService::class)->approve($actor, $opname);
            $payload['opname_status'] = $result->status;
        }
        $payload['outcome'] = 'success';
        $payload['exit_code'] = 0;
    } catch (Throwable $e) {
        $payload['exception'] = get_class($e);
        $payload['message'] = $e->getMessage();
        $payload['sql_state'] = $e instanceof QueryException ? $e->errorInfo[0] ?? null : null;
        $payload['error_code'] = $e instanceof QueryException ? $e->errorInfo[1] ?? null : null;
    }

    $payload['completed_at'] = microtime(true);
    @file_put_contents($resultPath, json_encode($payload));
    exit($payload['exit_code']);
}

if ($role === 'actor-a') {
    DB::beginTransaction();
    DB::table('concurrency_probe')->where('id', 1)->lockForUpdate()->first();
    DB::table('concurrency_probe')->where('id', 1)->update(['value' => 1]);

    $payload = [
        'success' => true,
        'connection_id' => $connectionId,
        'locked' => true,
        'released' => false,
        'timeline' => ['actor_a_begin', 'actor_a_locked'],
    ];
    @file_put_contents($actorAResult, json_encode($payload));
    @file_put_contents($actorAReady, 'ready');

    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($releaseFile)) {
        usleep(100000);
    }

    if (! file_exists($releaseFile)) {
        throw new RuntimeException('Actor A timed out waiting for release signal.');
    }

    DB::table('concurrency_probe')->where('id', 1)->update(['value' => 2]);
    DB::commit();

    $payload['released'] = true;
    $payload['timeline'][] = 'actor_a_released';
    @file_put_contents($actorAResult, json_encode($payload));

    exit(0);
}

if ($role === 'actor-b') {
    $payload = [
        'success' => false,
        'connection_id' => $connectionId,
        'attempted' => true,
        'blocked_while_a_owned_lock' => false,
        'completed_after_release' => false,
        'timeline' => ['actor_b_begin'],
    ];

    @file_put_contents($actorBAttempt, 'attempt');

    $blocked = false;
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! file_exists($releaseFile)) {
        $blocked = true;
        usleep(100000);
    }

    if (! file_exists($releaseFile)) {
        $payload['timeline'][] = 'actor_b_timeout';
        @file_put_contents($actorBResult, json_encode($payload));
        exit(1);
    }

    $payload['blocked_while_a_owned_lock'] = $blocked;

    try {
        DB::beginTransaction();
        $payload['timeline'][] = 'actor_b_tx_started';
        DB::table('concurrency_probe')->where('id', 1)->lockForUpdate()->first();
        $payload['timeline'][] = 'actor_b_lock_acquired';
        DB::table('concurrency_probe')->where('id', 1)->update(['value' => 3]);
        DB::commit();
        $payload['success'] = true;
        $payload['completed_after_release'] = true;
        $payload['timeline'][] = 'actor_b_commit';
    } catch (Throwable $e) {
        $payload['success'] = false;
        $payload['timeline'][] = 'actor_b_blocked';
        $payload['error'] = $e->getMessage();
    }

    @file_put_contents($actorBResult, json_encode($payload));
    exit($payload['success'] ? 0 : 1);
}

fwrite(STDERR, "Unknown role: {$role}\n");
exit(2);
