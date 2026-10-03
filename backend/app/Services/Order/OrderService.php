<?php

namespace App\Services\Order;

use App\DataTransferObjects\ShippingQuoteContext;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\KonsumenAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Models\Village;
use App\Services\Fee\FeeService;
use App\Services\Logging\ActivityLogger;
use App\Services\Payment\AvailablePaymentMethodService;
use App\Services\Payment\PaymentService;
use App\Services\Shipping\ShippingQuoteService;
use App\Services\Stock\StockRequestService;
use App\Services\Stock\StockSourceResolver;
use App\Services\Stock\SubStockService;
use App\Services\Stock\StockService;
use App\Services\Stock\WarehouseStockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place order totals get computed. Every price, fee, stock check and
 * shipping cost here is re-derived from the database inside a single
 * transaction — the client's request only ever supplies *identifiers and
 * quantities* (product id, variation id, qty, which saved address / which
 * courier), never a trusted amount. See Blueprint §Security and the task
 * instruction: never trust price/fee/stock/total from the frontend.
 */
class OrderService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly StockRequestService $stockRequestService,
        private readonly WarehouseStockService $warehouseStockService,
        private readonly InventoryCancellationService $inventoryCancellationService,
        private readonly ShippingQuoteService $shippingQuoteService,
        private readonly FeeService $feeService,
        private readonly PaymentService $paymentService,
        private readonly CourierService $courierService,
        private readonly AvailablePaymentMethodService $availablePaymentMethodService,
        private readonly SubStockService $subStockService,
        private readonly StockSourceResolver $stockSourceResolver,
        private readonly ShipmentGroupingService $shipmentGrouping,
    ) {}

    /**
     * @param  array<int, array{product_id:int, product_variation_id:?int, quantity:int}>  $lines
     * @param  array{address_id:?int, recipient_name:?string, recipient_phone:?string, address_line:?string, village_id:?string, latitude:?float, longitude:?float}  $destination
     *
     * $idempotencyKey (from the client's Idempotency-Key header) makes a
     * retried/duplicated submission safe: if an order already exists for
     * this konsumen+key (either found up front, or discovered via a unique
     * constraint race when two identical requests land concurrently — see
     * the `orders_konsumen_idempotency_unique` index), the existing order is
     * returned instead of creating a second one and reserving stock twice.
     */
    public function createOrder(
        User $konsumen,
        array $lines,
        array $destination,
        ?User $actor = null,
        ?string $paymentMethodCode = null,
        ?string $deliveryDate = null,
        ?string $idempotencyKey = null,
        ?string $shippingMethod = null,
        ?float $dpAmount = null,
        ?array $selectedCourierOption = null,
        ?string $stockSource = null,
        ?int $subLocationId = null,
    ): Order {
        $actor ??= $konsumen;

        if (! $konsumen->agent_id) {
            throw new ApiException(__('messages.order.no_agent_branch'), 422);
        }

        if (empty($lines)) {
            throw new ApiException(__('messages.order.empty_items'), 422);
        }

        if ($idempotencyKey) {
            $existing = Order::query()
                ->where('konsumen_id', $konsumen->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing->load(['items', 'shipments', 'paymentMethod', 'paymentTransactions.paymentMethod', 'paymentTransactions.bankTransferVerification']);
            }
        }

        $paymentMethod = $this->resolvePaymentMethod($paymentMethodCode);

        // R-02: the stock domain is decided server-side (own purchase / own-referral order only may use
        // Sub stock); the client's stock_source is a request, never an entitlement.
        $subLocation = $this->stockSourceResolver->resolve($actor, $konsumen, $stockSource, $subLocationId)['sub_location'];

        try {
            return DB::transaction(function () use ($konsumen, $lines, $destination, $actor, $paymentMethod, $deliveryDate, $idempotencyKey, $shippingMethod, $dpAmount, $selectedCourierOption, $subLocation) {
                $agentId = $konsumen->agent_id;

                $agentProfile = AgentProfile::query()->where('user_id', $agentId)->first();
                if (! $agentProfile) {
                    throw new ApiException(__('messages.order.agent_profile_incomplete'), 422);
                }

                $destinationSnapshot = $this->resolveDestination($konsumen, $destination);

                // COD never needs the "awaiting payment verification" step that
                // 'diterima' represents for manual-transfer/gateway orders — there
                // is nothing to verify before fulfillment can start, so a COD order
                // is created straight into 'diproses' (Blueprint: "Order COD dapat
                // langsung diproses").
                $initialStatus = $paymentMethod->type === 'cod' ? 'diproses' : 'diterima';

                // Placeholder order — totals are filled in after items are priced below.
                $order = Order::create([
                    'order_no' => 'TEMP',
                    'idempotency_key' => $idempotencyKey,
                    'konsumen_id' => $konsumen->id,
                    'sales_id' => $konsumen->sales_id,
                    'korsal_id' => $konsumen->korsal_id,
                    'agent_id' => $agentId,
                    'payment_method_id' => $paymentMethod->id,
                    'source_address_id' => $destinationSnapshot['address_id'],
                    'status' => $initialStatus,
                    'payment_status' => 'unpaid',
                    'subtotal_amount' => 0,
                    'shipping_fee_amount' => 0,
                    'admin_fee_amount' => 0,
                    'total_amount' => 0,
                    'recipient_name_snapshot' => $destinationSnapshot['recipient_name'],
                    'recipient_phone_snapshot' => $destinationSnapshot['recipient_phone'],
                    'address_snapshot' => $destinationSnapshot['address_line'],
                    'village_snapshot' => $destinationSnapshot['village_name'],
                    'district_snapshot' => $destinationSnapshot['district_name'],
                    'regency_snapshot' => $destinationSnapshot['regency_name'],
                    'province_snapshot' => $destinationSnapshot['province_name'],
                    'latitude_snapshot' => $destinationSnapshot['latitude'],
                    'longitude_snapshot' => $destinationSnapshot['longitude'],
                    'delivery_date_estimate' => $deliveryDate,
                ]);

                $subtotal = 0.0;
                $totalWeightGrams = 0;

                // Resolve every line to its ONE authoritative inventory target BEFORE any inventory
                // lock is taken. A simple product is a product target and must not carry a variation
                // id; a variation product is a variation target. The same resolution feeds both the
                // canonical prelock below and the per-line reserve, so the prelocked target can never
                // differ from the target actually reserved (the mismatch MAJOR A closed).
                $resolvedLines = [];
                foreach ($lines as $line) {
                    $resolvedLines[] = $this->resolveLine($line);
                }
                $reservationTargets = array_map(fn (array $resolved) => $this->resolvedTarget($resolved), $resolvedLines);

                // Canonical multi-target lock order: acquire every effective target in the same
                // deterministic order a multi-target Transit -> Sub execution uses, so two concurrent
                // transactions listing the same targets in opposite orders cannot each hold one and
                // wait on the other. Agent and Sub targets lock different rows but share the vocabulary.
                if ($subLocation) {
                    // Canonical Sub-domain order: the Sub Location parent row FIRST (shared), then the
                    // Sub stock target rows. Without the parent lock first, a concurrent Gudang
                    // execution (location exclusive -> stock) can cycle with the checkout's
                    // order_item FK insert (stock held -> parent shared lock needed).
                    $subLocation = $this->lockSubLocationForCheckout($subLocation, $actor);
                    $this->subStockService->lockReservationTargets($subLocation->id, $reservationTargets);
                } else {
                    $this->stockService->lockReservationTargets($agentId, $reservationTargets);
                }

                // The per-line loop still runs in the incoming order, so order-item creation and
                // presentation order are untouched — the rows are already held by then.
                foreach ($resolvedLines as $resolved) {
                    [$lineSubtotal, $lineWeight] = $this->priceAndReserveLine($order, $agentId, $konsumen, $actor, $resolved, $subLocation);
                    $subtotal += $lineSubtotal;
                    $totalWeightGrams += $lineWeight;
                }

                $adminFee = (float) Setting::get('checkout.admin_fee_amount', '0');

                $quote = $this->shippingQuoteService->quote(new ShippingQuoteContext(
                    originLat: (float) $agentProfile->latitude,
                    originLng: (float) $agentProfile->longitude,
                    destLat: (float) $destinationSnapshot['latitude'],
                    destLng: (float) $destinationSnapshot['longitude'],
                    destRegencyId: $destinationSnapshot['regency_id'],
                    destVillageId: $destinationSnapshot['village_id'],
                    weightGrams: $totalWeightGrams,
                    subtotal: $subtotal,
                    agentId: $agentId,
                    preferredProvider: $shippingMethod ?? ($selectedCourierOption ? 'rajaongkir' : null),
                    selectedCourierOption: $selectedCourierOption,
                ));

                // Authoritative — never trusts the client's own shipping_method
                // hint for this check, only the ACTUALLY resolved provider
                // (ShippingQuoteService may fall back/differ from what was
                // requested). Also re-enforces the agent's own enabled/disabled
                // payment methods, which nothing before this point checked.
                if (! $this->availablePaymentMethodService->isAvailable($agentId, $quote->providerCode, $paymentMethod)) {
                    throw new ApiException(
                        __('messages.payment.method_not_available_for_shipping'),
                        422,
                        ['payment_method_code' => __('messages.system.field_invalid')],
                    );
                }

                $shippingFee = $quote->cost;
                $total = $subtotal + $shippingFee + $adminFee;

                $order->update([
                    'order_no' => 'PC-'.now()->format('ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                    'subtotal_amount' => $subtotal,
                    'shipping_fee_amount' => $shippingFee,
                    'admin_fee_amount' => $adminFee,
                    'total_amount' => $total,
                ]);

                // DOWN PAYMENT: the konsumen pays part now; the rest stays
                // outstanding. Validated server-side against the freshly
                // computed total — never against a client-supplied amount.
                if ($paymentMethod->code === 'down_payment') {
                    if ($dpAmount === null || $dpAmount <= 0 || $dpAmount >= $total) {
                        throw new ApiException(
                            __('messages.payment.invalid_dp_amount'),
                            422,
                            ['dp_amount' => __('messages.payment.invalid_dp_amount')],
                        );
                    }

                    $order->update([
                        'dp_amount' => round($dpAmount, 2),
                        'paid_amount' => 0,
                        'remaining_amount' => $total,
                    ]);
                }

                $providerRow = ! in_array($quote->providerCode, ['free', 'pickup'], true)
                    ? ShippingProvider::query()->where('code', $quote->providerCode)->first()
                    : null;

                // LOCKED rule: shipment / delivery / resi = ORDER + REQUESTED DELIVERY DATE (not per product).
                // Every item is placed through the canonical ShipmentGroupingService, so items sharing a date
                // share ONE shipment. The real shipping_fee_snapshot/rate_per_km live on exactly one (the first)
                // shipment — Order.shipping_fee_amount is already the authoritative order-level total.
                $orderItems = OrderItem::query()->where('order_id', $order->id)->get();

                foreach ($orderItems as $item) {
                    $firstShipment = ! Shipment::query()->where('order_id', $order->id)->exists();
                    $shipment = $this->shipmentGrouping->resolveMutableShipmentFor($order, $item->requested_delivery_date?->toDateString(), [
                        'shipping_provider_id' => $providerRow?->id,
                        'shipping_provider_code' => $quote->providerCode,
                        'origin_latitude' => $agentProfile->latitude,
                        'origin_longitude' => $agentProfile->longitude,
                        'destination_latitude' => $destinationSnapshot['latitude'],
                        'destination_longitude' => $destinationSnapshot['longitude'],
                        'distance_km' => $quote->distanceKm,
                        'provider_meta' => $quote->meta,
                        'status' => 'pending',
                        // R-03: a Sub-sourced order is delivered by its owning Sales-Kurir-Sub, never a
                        // Kurir — the shipment is created as self_sub with that stable user reference
                        // (courier_id stays NULL). Agent-sourced orders keep the standard Kurir path.
                        'delivery_mode' => $subLocation ? Shipment::DELIVERY_MODE_SELF_SUB : Shipment::DELIVERY_MODE_STANDARD,
                        'self_delivered_by_user_id' => $subLocation?->owner_user_id,
                        ...($firstShipment ? ['rate_per_km' => $quote->ratePerKm, 'shipping_fee_snapshot' => $shippingFee] : []),
                    ]);

                    $item->update(['shipment_id' => $shipment->id]);
                }

                if ($initialStatus === 'diproses') {
                    $this->stockRequestService->createForOrderWhenProcessing($order);
                }

                $this->paymentService->initiate($order, $paymentMethod);

                ActivityLogger::log($actor->id, $order, 'order.created', null, [
                    'total_amount' => $total,
                    'item_count' => count($lines),
                    'konsumen_id' => $konsumen->id,
                    'shipping_provider' => $quote->providerCode,
                ]);

                return $order->fresh(['items', 'shipments.courier.user', 'paymentMethod', 'paymentTransactions.paymentMethod', 'paymentTransactions.bankTransferVerification']);
            });
        } catch (QueryException $e) {
            // Two concurrent requests with the same Idempotency-Key both
            // passed the pre-check above — the DB unique index rejected the
            // loser. Return the winner's order instead of a 500.
            if ($idempotencyKey && str_contains($e->getMessage(), 'orders_konsumen_idempotency_unique')) {
                return Order::query()
                    ->where('konsumen_id', $konsumen->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail()
                    ->load(['items', 'shipments.courier.user', 'paymentMethod', 'paymentTransactions.paymentMethod', 'paymentTransactions.bankTransferVerification']);
            }

            throw $e;
        }
    }

    private function resolvePaymentMethod(?string $code): PaymentMethod
    {
        if (! $code) {
            throw new ApiException(__('messages.payment.method_required'), 422, ['payment_method_code' => __('messages.system.field_required')]);
        }

        $method = PaymentMethod::query()->where('code', $code)->where('is_active', true)->first();

        if (! $method) {
            throw new ApiException(__('messages.payment.invalid_method'), 422, ['payment_method_code' => __('messages.system.field_invalid')]);
        }

        return $method;
    }

    /**
     * Read-only preview for the checkout "Review" step — re-derives the same
     * subtotal/shipping/admin-fee/total createOrder() would charge, without
     * reserving stock or writing anything. The frontend renders this
     * response verbatim; it never computes or edits a total itself.
     *
     * @param  array<int, array{product_id:int, product_variation_id:?int, quantity:int}>  $lines
     * @param  array{address_id:?int, recipient_name:?string, recipient_phone:?string, address_line:?string, village_id:?string, latitude:?float, longitude:?float}  $destination
     * @return array{subtotal_amount:float, shipping_fee_amount:float, admin_fee_amount:float, total_amount:float, distance_km:?float, shipping_provider:string, shipping_enabled:bool, warnings: array<int, array{product_id:int, product_variation_id:?int, message:string}>}
     */
    public function quote(User $konsumen, array $lines, array $destination, ?string $shippingMethod = null, ?array $selectedCourierOption = null): array
    {
        if (! $konsumen->agent_id) {
            throw new ApiException(__('messages.order.no_agent_branch'), 422);
        }

        if (empty($lines)) {
            throw new ApiException(__('messages.order.empty_items'), 422);
        }

        $agentId = $konsumen->agent_id;
        $agentProfile = AgentProfile::query()->where('user_id', $agentId)->first();

        if (! $agentProfile) {
            throw new ApiException(__('messages.order.agent_profile_incomplete'), 422);
        }

        $destinationSnapshot = $this->resolveDestination($konsumen, $destination);

        $subtotal = 0.0;
        $totalWeightGrams = 0;
        $warnings = [];

        foreach ($lines as $line) {
            [$lineSubtotal, $lineWeight, $warning] = $this->quoteLine($agentId, $line);
            $subtotal += $lineSubtotal;
            $totalWeightGrams += $lineWeight;

            if ($warning) {
                $warnings[] = $warning;
            }
        }

        $adminFee = (float) Setting::get('checkout.admin_fee_amount', '0');

        $quote = $this->shippingQuoteService->quote(new ShippingQuoteContext(
            originLat: (float) $agentProfile->latitude,
            originLng: (float) $agentProfile->longitude,
            destLat: (float) $destinationSnapshot['latitude'],
            destLng: (float) $destinationSnapshot['longitude'],
            destRegencyId: $destinationSnapshot['regency_id'],
            destVillageId: $destinationSnapshot['village_id'],
            weightGrams: $totalWeightGrams,
            subtotal: $subtotal,
            agentId: $agentId,
            preferredProvider: $shippingMethod ?? ($selectedCourierOption ? 'rajaongkir' : null),
            selectedCourierOption: $selectedCourierOption,
        ));

        return [
            'subtotal_amount' => round($subtotal, 2),
            'shipping_fee_amount' => $quote->cost,
            'admin_fee_amount' => $adminFee,
            'total_amount' => round($subtotal + $quote->cost + $adminFee, 2),
            'distance_km' => $quote->distanceKm,
            'shipping_provider' => $quote->providerCode,
            'shipping_enabled' => $this->shippingQuoteService->isShippingSelectionAvailable($agentId),
            'warnings' => $warnings,
        ];
    }

    /**
     * Every courier/service "Ekspedisi" (RajaOngkir) currently offers for
     * this destination+cart, cheapest first — feeds the checkout's "pick a
     * courier" sub-step once the konsumen has chosen "Ekspedisi" over "Kurir
     * Online". Read-only, same as quote() — never reserves stock.
     *
     * @param  array<int, array{product_id:int, product_variation_id:?int, quantity:int}>  $lines
     * @param  array{address_id:?int, recipient_name:?string, recipient_phone:?string, address_line:?string, village_id:?string, latitude:?float, longitude:?float}  $destination
     * @return array<int, array{courier: string, service: string, cost: float, etd: ?string}>
     */
    public function courierOptions(User $konsumen, array $lines, array $destination): array
    {
        if (! $konsumen->agent_id) {
            throw new ApiException(__('messages.order.no_agent_branch'), 422);
        }

        if (empty($lines)) {
            throw new ApiException(__('messages.order.empty_items'), 422);
        }

        $agentId = $konsumen->agent_id;
        $agentProfile = AgentProfile::query()->where('user_id', $agentId)->first();

        if (! $agentProfile) {
            throw new ApiException(__('messages.order.agent_profile_incomplete'), 422);
        }

        $destinationSnapshot = $this->resolveDestination($konsumen, $destination);

        $totalWeightGrams = 0;
        foreach ($lines as $line) {
            [, $lineWeight] = $this->quoteLine($agentId, $line);
            $totalWeightGrams += $lineWeight;
        }

        return $this->shippingQuoteService->courierOptions(new ShippingQuoteContext(
            originLat: (float) $agentProfile->latitude,
            originLng: (float) $agentProfile->longitude,
            destLat: (float) $destinationSnapshot['latitude'],
            destLng: (float) $destinationSnapshot['longitude'],
            destRegencyId: $destinationSnapshot['regency_id'],
            destVillageId: $destinationSnapshot['village_id'],
            weightGrams: $totalWeightGrams,
            subtotal: 0.0,
            agentId: $agentId,
        ));
    }

    /**
     * Resolves the ONE authoritative inventory target of an order line. This is the single source of
     * truth consumed by both the canonical capacity prelock and the actual reserve/deduct path, so
     * the prelocked target can never differ from the target actually reserved.
     *
     * A product with has_variations = false is a PRODUCT target and must not carry a variation id; a
     * variation product is a VARIATION target and requires an active variation of THAT product.
     *
     * @param  array{product_id:int, product_variation_id?:?int, quantity?:int}  $line
     * @return array{product: Product, variation: ?ProductVariation, quantity: int}
     */
    public function resolveLine(array $line): array
    {
        $product = Product::query()->where('status', 'active')->findOrFail($line['product_id']);
        $variationId = $line['product_variation_id'] ?? null;

        if ($product->has_variations) {
            if (empty($variationId)) {
                throw new ApiException(__('messages.product.variation_required', ['name' => $product->name]), 422);
            }

            $variation = ProductVariation::query()
                ->where('product_id', $product->id)->where('is_active', true)
                ->findOrFail($variationId);
        } else {
            if (! empty($variationId)) {
                // A simple product has no variation target. Refuse the line outright rather than
                // silently ignoring the supplied id — ignoring it would prelock a variation the
                // reserve path never touches.
                throw new ApiException(__('messages.product.variation_not_allowed', ['name' => $product->name]), 422);
            }

            $variation = null;
        }

        return ['product' => $product, 'variation' => $variation, 'quantity' => (int) ($line['quantity'] ?? 0)];
    }

    /** @param array{product: Product, variation: ?ProductVariation} $resolved @return array{product_id:int, product_variation_id:?int} */
    private function resolvedTarget(array $resolved): array
    {
        return ['product_id' => $resolved['product']->id, 'product_variation_id' => $resolved['variation']?->id];
    }

    /**
     * Canonical Sub-domain lock order, parent first: a Sub-sourced checkout takes the Sub Location row
     * BEFORE any Sub stock target lock. A concurrent Gudang execution locks the SAME location
     * exclusively and then the stock rows (SubStockRequestService::execute), so if checkout instead
     * held stock first and only needed the parent at the order_item FK insert, the two would form a
     * location <-> stock cycle.
     *
     * A SHARED lock is sufficient and preferred: checkout only needs the parent to stay stable while
     * it creates FK-backed order items and reserves stock, and multiple checkouts may hold it
     * concurrently, while the executor's exclusive lock waits at the location boundary. The freshly
     * locked row is re-validated — the pre-transaction snapshot is never trusted.
     */
    private function lockSubLocationForCheckout(WarehouseSubLocation $location, User $actor): WarehouseSubLocation
    {
        $locked = WarehouseSubLocation::withoutGlobalScopes()
            ->whereKey($location->id)
            ->sharedLock()
            ->first();

        $valid = $locked
            && $locked->is_active
            && $locked->agent_id === $actor->agent_id
            && $locked->owner_user_id === $actor->id;

        if (! $valid) {
            throw new ApiException('Sub Location tidak lagi aktif atau bukan milik Anda.', 422, ['stock_source' => 'Sub Location tidak tersedia.']);
        }

        return $locked;
    }

    /** @return array{0: float, 1: int, 2: ?array{product_id:int, product_variation_id:?int, message:string}} */
    private function quoteLine(int $agentId, array $line): array
    {
        $resolved = $this->resolveLine($line);
        $product = $resolved['product'];
        $variation = $resolved['variation'];
        $qty = max(1, $resolved['quantity']);

        if ($variation) {
            $unitPrice = (float) $variation->price;
            $unitWeight = (int) ($variation->weight_grams ?: $product->weight_grams);
        } else {
            $unitPrice = (float) $product->base_price;
            $unitWeight = (int) $product->weight_grams;
        }

        if ($unitWeight < 1) {
            throw new ApiException('Berat produk belum dikonfigurasi.', 422, ['items' => 'Berat produk wajib minimal 1 gram.']);
        }

        $available = $variation
            ? $this->warehouseStockService->sellableForVariation($agentId, $variation->id)['available']
            : $this->warehouseStockService->sellableForProduct($agentId, $product->id)['available'];
        $warning = $available < $qty ? [
            'product_id' => $product->id,
            'product_variation_id' => $variation?->id,
            'message' => __('messages.order.insufficient_stock', ['item' => $variation?->label() ?? $product->name]),
        ] : null;

        return [$unitPrice * $qty, $unitWeight * $qty, $warning];
    }

    /**
     * Prices one already-resolved line, reserves its stock, records its fee/commission snapshot.
     * Returns [subtotal, weightGrams]. $resolved comes from resolveLine() so the reserved target is
     * exactly the target the caller prelocked.
     *
     * @param  array{product: Product, variation: ?ProductVariation, quantity: int}  $resolved
     */
    private function priceAndReserveLine(Order $order, int $agentId, User $konsumen, User $actor, array $resolved, ?WarehouseSubLocation $subLocation = null, ?string $requestedDeliveryDate = null, ?string $idempotencyKey = null): array
    {
        $product = $resolved['product'];
        $variation = $resolved['variation'];
        $qty = $resolved['quantity'];

        if ($qty < 1) {
            throw new ApiException(__('messages.order.invalid_quantity'), 422);
        }

        if ($variation) {
            $unitPrice = (float) $variation->price;
            $unitWeight = (int) ($variation->weight_grams ?: $product->weight_grams);
        } else {
            $unitPrice = (float) $product->base_price;
            $unitWeight = (int) $product->weight_grams;
        }

        if ($unitWeight < 1) {
            throw new ApiException('Berat produk belum dikonfigurasi.', 422, ['items' => 'Berat produk wajib minimal 1 gram.']);
        }

        // Agent stock is reserved only for Agent-sourced lines. A Sub-sourced line reserves in the Sub
        // ledger instead (below, once the item row exists) and never touches Agent Reserved.
        if (! $subLocation) {
            if ($variation) {
                $this->stockService->reserveForVariation($agentId, $variation, $qty, 'order', $order->id, $actor->id);
            } else {
                $this->stockService->reserveForProduct($agentId, $product, $qty, 'order', $order->id, $actor->id);
            }
        }

        // Snapshotted onto the order_item below — a later change to product_fees/
        // product_variation_fees never touches this row again (Blueprint example:
        // sales fee was Rp8.000 at purchase time, later raised to Rp15.000 — this
        // order keeps Rp8.000 forever). Fee is configured PER UNIT, same as
        // unit_price — scaled by quantity here exactly like subtotal is, so
        // buying 3 never earns the same commission as buying 1.
        $fees = $this->feeService->resolveForLine($product, $variation);
        $agentFee = $fees['agent'] * $qty;
        $salesFee = $fees['sales'] * $qty;
        $courierFee = $fees['courier'] * $qty;
        $lineSubtotal = $unitPrice * $qty;

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variation_id' => $variation?->id,
            'stock_source' => $subLocation ? StockSourceResolver::SUB : StockSourceResolver::AGENT,
            'sub_location_id' => $subLocation?->id,
            'product_name_snapshot' => $product->name,
            'variation_label_snapshot' => $variation?->label(),
            'sku_snapshot' => $variation ? $variation->sku : $product->sku,
            'unit_price_snapshot' => $unitPrice,
            'agent_fee_amount' => $agentFee,
            'sales_fee_amount' => $salesFee,
            'courier_fee_amount' => $courierFee,
            'subtotal_snapshot' => $lineSubtotal,
            'original_quantity' => $qty,
            'fulfilled_quantity' => $qty,
            // Package C / SC-03: an added line may carry its own requested date; a checkout line
            // keeps the existing default (the order's own estimate) and a NULL idempotency key
            // (only SC-03 appends carry one, uniquely per order).
            'requested_delivery_date' => $requestedDeliveryDate ?? $order->delivery_date_estimate,
            'idempotency_key' => $idempotencyKey,
            'status' => $order->status,
        ]);

        if ($subLocation) {
            $this->subStockService->reserve($orderItem, $subLocation->id, $agentId, $qty, $actor);
        }

        // Sales commission recipient:
        //
        // Self-purchase (the "konsumen" buying is itself an agen/korsal/
        // sales — see OrderController::resolveKonsumen/OrderPolicy::create)
        // — the buyer IS the referral owner for their own purchase, full
        // stop. Their own sales_id/korsal_id fields mean "who is MY upline",
        // which is the wrong question here (Blueprint §Fee: "buyer" and
        // "referrer" are different concepts, but for a self-purchase they
        // are deliberately the same person) — using that fallback chain
        // would misattribute a korsal/sales's own self-purchase commission
        // up to their upline instead of to themselves.
        //
        // Otherwise (an actual konsumen, whether buying for themselves or
        // being ordered for by their agen/korsal/sales): whoever directly
        // referred them — their sales rep if one exists, else the korsal
        // who referred them directly (no sales in between), else the agen
        // itself when the konsumen came straight from the agen's own
        // referral code. In those last two cases the korsal/agen is acting
        // as the "sales" for this konsumen, and earns BOTH their own
        // agent_fee (above, unaffected) AND this sales_fee, as two separate
        // commissions (never merged into one, never double-counted).
        $salesBeneficiaryId = $konsumen->isRole('agen', 'korsal', 'sales')
            ? $konsumen->id
            : ($konsumen->sales_id ?? $konsumen->korsal_id ?? $agentId);

        $this->recordCommission($order, $orderItem, $agentId, $salesBeneficiaryId, $agentFee, $salesFee);

        return [$lineSubtotal, $unitWeight * $qty, $orderItem];
    }

    /**
     * Package C / SC-03: writes (snapshot + reserve + commission) ONE new Agent-sourced line for an
     * existing order, reusing the EXACT canonical checkout path (resolveLine + priceAndReserveLine)
     * so pricing/fee/snapshot/reservation semantics can never drift between checkout and addition.
     *
     * The caller (OrderLineAdditionService) is responsible for the order row lock, the canonical
     * inventory target prelock, the Stock Request reconciliation and the order-level financial
     * reconciliation — this method only owns the line itself.
     *
     * @param  array{product: Product, variation: ?ProductVariation, quantity: int}  $resolved
     */
    public function addReservedLine(Order $order, User $konsumen, User $actor, array $resolved, ?string $requestedDeliveryDate = null, ?string $idempotencyKey = null): OrderItem
    {
        [, , $orderItem] = $this->priceAndReserveLine($order, $order->agent_id, $konsumen, $actor, $resolved, null, $requestedDeliveryDate, $idempotencyKey);

        return $orderItem;
    }

    private function recordCommission(Order $order, OrderItem $item, int $agentId, int $salesBeneficiaryId, float $agentFee, float $salesFee): void
    {
        if ($agentFee > 0) {
            Commission::create([
                'order_id' => $order->id, 'order_item_id' => $item->id,
                'beneficiary_user_id' => $agentId, 'beneficiary_role' => 'agent',
                'amount' => $agentFee, 'status' => 'pending', 'earned_at' => now(),
            ]);
        }

        if ($salesFee > 0) {
            Commission::create([
                'order_id' => $order->id, 'order_item_id' => $item->id,
                'beneficiary_user_id' => $salesBeneficiaryId, 'beneficiary_role' => 'sales',
                'amount' => $salesFee, 'status' => 'pending', 'earned_at' => now(),
            ]);
        }
    }

    /**
     * @return array{address_id:?int, recipient_name:string, recipient_phone:string,
     *     address_line:string, latitude:float, longitude:float, regency_id:?string,
     *     village_name:?string, district_name:?string, regency_name:?string, province_name:?string}
     */
    private function resolveDestination(User $konsumen, array $destination): array
    {
        if (! empty($destination['address_id'])) {
            $address = KonsumenAddress::query()
                ->with(['village.district.regency.province'])
                ->where('user_id', $konsumen->id)
                ->findOrFail($destination['address_id']);

            return [
                'address_id' => $address->id,
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->phone,
                'address_line' => $address->address_line,
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
                'regency_id' => $address->regency_id,
                'village_id' => $address->village_id,
                'village_name' => $address->village?->name,
                'district_name' => $address->district?->name,
                'regency_name' => $address->regency?->name,
                'province_name' => $address->province?->name,
            ];
        }

        $village = ! empty($destination['village_id'])
            ? Village::query()->with('district.regency.province')->find($destination['village_id'])
            : null;

        if (! empty($destination['village_id']) && ! $village) {
            throw new ApiException(__('messages.order.invalid_village'), 422, ['village_id' => __('messages.system.field_invalid')]);
        }

        return [
            'address_id' => null,
            'recipient_name' => $destination['recipient_name'],
            'recipient_phone' => $destination['recipient_phone'],
            'address_line' => $destination['address_line'],
            'latitude' => (float) $destination['latitude'],
            'longitude' => (float) $destination['longitude'],
            'regency_id' => $village?->district?->regency_id,
            'village_id' => $village?->id,
            'village_name' => $village?->name,
            'district_name' => $village?->district?->name,
            'regency_name' => $village?->district?->regency?->name,
            'province_name' => $village?->district?->regency?->province?->name,
        ];
    }

    /**
     * Generic forward-progress transition (diterima -> diproses -> dikirim ->
     * terkirim). Cancellation has its own method (stock must be released);
     * return/refund flows own the 'pengembalian'/'kembali' states — this
     * never accepts those as a target. The transition table on Order is the
     * single source of truth for what's legal, enforced identically
     * regardless of the actor's role, super_admin included (Blueprint rule:
     * "SUPERADMIN tidak boleh mengubah status order jika business rule
     * melarang").
     */
    public function updateStatus(Order $order, string $newStatus, User $actor): Order
    {
        if (! in_array($newStatus, ['diproses', 'dikirim', 'terkirim'], true)) {
            throw new ApiException(__('messages.order.status_endpoint_required', ['status' => $newStatus]), 422);
        }

        return DB::transaction(function () use ($order, $newStatus, $actor) {
            $order = Order::query()->with('paymentMethod')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->canTransitionTo($newStatus)) {
                throw new InvalidStateTransitionException($order->status, $newStatus);
            }

            // Payment eligibility is state-dependent and therefore belongs
            // after the row lock, beside transition validation. A concurrent
            // payment/status mutation cannot leave this decision stale.
            $isCod = $order->paymentMethod?->type === 'cod';
            $paymentReady = $order->payment_status === 'paid'
                || ($order->paymentMethod?->code === 'down_payment' && $order->payment_status === 'partially_paid');

            if ($newStatus === 'diproses' && ! $isCod && ! $paymentReady) {
                throw new ApiException(__('messages.order.payment_not_verified'), 422);
            }

            $previousStatus = $order->status;
            $order->update(['status' => $newStatus]);

            if ($newStatus === 'diproses' && $previousStatus !== 'diproses') {
                $this->stockRequestService->createForOrderWhenProcessing($order);
            }

            // Per-item status mirrors the order (Blueprint: "status harus
            // diperiksa PER ORDER ITEM") — an item already 'dibatalkan' at the
            // fulfillment stage (see OrderFulfillmentService) stays there,
            // never bulk-overwritten back into the forward flow. This is the
            // office-driven bulk override (kurir never reaches this endpoint —
            // see OrderPolicy::updateStatus — they use CourierService's
            // per-shipment updateShipmentStatus() instead), so every shipment
            // on the order is kept in sync here regardless of which courier
            // holds it.
            $shipmentIds = [];
            foreach ($order->items as $item) {
                if ($item->canTransitionTo($newStatus)) {
                    // R-03: Sub-sourced goods ship ONLY through the owning Sales-Kurir-Sub's
                    // self-delivery path (CourierService::updateShipmentStatus, which checks
                    // self_delivered_by_user_id and requires delivery proof). This generic office
                    // bulk override must never advance a Sub item to 'dikirim' OR 'terkirim' —
                    // doing so would bypass both the ownership check and the proof requirement.
                    if (in_array($newStatus, ['dikirim', 'terkirim'], true) && $item->isSubSourced()) {
                        throw new ApiException(__('messages.order.sub_item_requires_owner_shipment'), 422);
                    }
                    $item->update(['status' => $newStatus]);
                    if ($item->shipment_id) {
                        $shipmentIds[$item->shipment_id] = true;
                    }
                }
            }

            if (in_array($newStatus, ['dikirim', 'terkirim'], true)) {
                foreach (Shipment::query()->whereIn('id', array_keys($shipmentIds))->get() as $shipment) {
                    $shipment->update(match ($newStatus) {
                        'dikirim' => ['status' => 'in_transit', 'shipped_at' => $shipment->shipped_at ?? now()],
                        'terkirim' => ['status' => 'delivered', 'delivered_at' => now()],
                    });
                }
            }

            ActivityLogger::log($actor->id, $order, 'order.status_changed', null, [
                'from' => $previousStatus, 'to' => $newStatus, 'actor_role' => $actor->role?->slug,
            ]);

            // Courier delivery fee is only earned once delivery actually
            // completes — never at order creation (see CourierService docblock).
            if ($newStatus === 'terkirim') {
                $this->courierService->recordCommissionsOnDelivery($order->fresh('items'));
            }

            return $order->fresh();
        });
    }

    /**
     * Cancellation business rule (Blueprint §Cancellation), enforced
     * identically for every actor including super_admin — "tidak boleh
     * mengubah status order jika spesifikasi menyatakan demikian":
     *   - COD: 'diterima' or 'diproses' may still be cancelled.
     *   - Non-COD (manual transfer / gateway): only 'diterima' may be
     *     cancelled — once diproses, payment has already been verified/
     *     collected, so cancelling requires the refund/return flow instead.
     */
    public function cancel(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->with('paymentMethod')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->canTransitionTo('dibatalkan')) {
                throw new InvalidStateTransitionException($order->status, 'dibatalkan');
            }

            $isCod = $order->paymentMethod?->type === 'cod';
            $allowedStatuses = $isCod ? ['diterima', 'diproses'] : ['diterima'];

            if (! in_array($order->status, $allowedStatuses, true)) {
                throw new InvalidStateTransitionException($order->status, 'dibatalkan');
            }

            $this->inventoryCancellationService->reverseOrder($order, $actor->id);
            foreach ($order->items as $item) {
                if ($item->canTransitionTo('dibatalkan')) {
                    $item->update(['status' => 'dibatalkan', 'cancelled_quantity' => $item->cancelled_quantity + max(0, $item->original_quantity - $item->cancelled_quantity - $item->returned_quantity)]);
                }
            }

            $order->update([
                'status' => 'dibatalkan',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            // Full audit trail — actor, actor role, timestamp (created_at),
            // reason, and the order itself (subject) — never just a bare
            // status flip (Blueprint: "Tidak boleh hanya mengubah status
            // tanpa histori").
            ActivityLogger::log($actor->id, $order, 'order.cancelled', $reason, [
                'previous_status' => $order->getOriginal('status'),
                'actor_role' => $actor->role?->slug,
            ]);

            return $order->fresh();
        });
    }
}
