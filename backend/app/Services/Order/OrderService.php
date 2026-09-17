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
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Models\Village;
use App\Services\Fee\FeeService;
use App\Services\Logging\ActivityLogger;
use App\Services\Payment\AvailablePaymentMethodService;
use App\Services\Payment\PaymentService;
use App\Services\Shipping\ShippingQuoteService;
use App\Services\Stock\StockService;
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
        private readonly ShippingQuoteService $shippingQuoteService,
        private readonly FeeService $feeService,
        private readonly PaymentService $paymentService,
        private readonly CourierService $courierService,
        private readonly AvailablePaymentMethodService $availablePaymentMethodService,
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
                return $existing->load(['items', 'shipments', 'paymentMethod', 'paymentTransactions.bankTransferVerification']);
            }
        }

        $paymentMethod = $this->resolvePaymentMethod($paymentMethodCode);

        try {
            return DB::transaction(function () use ($konsumen, $lines, $destination, $actor, $paymentMethod, $deliveryDate, $idempotencyKey, $shippingMethod, $dpAmount, $selectedCourierOption) {
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

                foreach ($lines as $line) {
                    [$lineSubtotal, $lineWeight] = $this->priceAndReserveLine($order, $agentId, $konsumen, $actor, $line);
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

                // Every item gets its OWN Shipment from the start — never a
                // shared one — so a kurir's pickup/deliver action on one
                // product never touches its siblings (Blueprint: "satu order
                // bisa beberapa kurir"; this is the same split this order
                // would otherwise only reach via a later reschedule, see
                // OrderFulfillmentService::rescheduleItemDeliveryDate).
                // The real shipping_fee_snapshot/rate_per_km live on exactly
                // one (the first) shipment — Order.shipping_fee_amount is
                // already the authoritative order-level total; duplicating
                // the fee onto every per-item shipment would only make it
                // look like the order was charged shipping N times.
                $orderItems = OrderItem::query()->where('order_id', $order->id)->get();

                foreach ($orderItems as $index => $item) {
                    $shipment = Shipment::create([
                        'order_id' => $order->id,
                        'shipping_provider_id' => $providerRow?->id,
                        'shipping_provider_code' => $quote->providerCode,
                        'origin_latitude' => $agentProfile->latitude,
                        'origin_longitude' => $agentProfile->longitude,
                        'destination_latitude' => $destinationSnapshot['latitude'],
                        'destination_longitude' => $destinationSnapshot['longitude'],
                        'distance_km' => $quote->distanceKm,
                        'provider_meta' => $quote->meta,
                        'status' => 'pending',
                        ...($index === 0 ? ['rate_per_km' => $quote->ratePerKm, 'shipping_fee_snapshot' => $shippingFee] : []),
                    ]);

                    $item->update(['shipment_id' => $shipment->id]);
                }

                $this->paymentService->initiate($order, $paymentMethod);

                ActivityLogger::log($actor->id, $order, 'order.created', null, [
                    'total_amount' => $total,
                    'item_count' => count($lines),
                    'konsumen_id' => $konsumen->id,
                    'shipping_provider' => $quote->providerCode,
                ]);

                return $order->fresh(['items', 'shipments.courier.user', 'paymentMethod', 'paymentTransactions.bankTransferVerification']);
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
                    ->load(['items', 'shipments.courier.user', 'paymentMethod', 'paymentTransactions.bankTransferVerification']);
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

    /** @return array{0: float, 1: int, 2: ?array{product_id:int, product_variation_id:?int, message:string}} */
    private function quoteLine(int $agentId, array $line): array
    {
        $product = Product::query()->where('status', 'active')->findOrFail($line['product_id']);
        $qty = max(1, (int) $line['quantity']);
        $variation = null;

        if ($product->has_variations) {
            if (empty($line['product_variation_id'])) {
                throw new ApiException(__('messages.product.variation_required', ['name' => $product->name]), 422);
            }

            $variation = ProductVariation::query()
                ->where('product_id', $product->id)->where('is_active', true)
                ->findOrFail($line['product_variation_id']);

            $unitPrice = (float) $variation->price;
            $unitWeight = (int) ($variation->weight_grams ?: $product->weight_grams);
            $stock = ProductVariationStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)->where('product_variation_id', $variation->id)->first();
        } else {
            $unitPrice = (float) $product->base_price;
            $unitWeight = (int) $product->weight_grams;
            $stock = ProductStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)->where('product_id', $product->id)->first();
        }

        if ($unitWeight < 1) {
            throw new ApiException('Berat produk belum dikonfigurasi.', 422, ['items' => 'Berat produk wajib minimal 1 gram.']);
        }

        $available = $stock?->availableQuantity() ?? 0;
        $warning = $available < $qty ? [
            'product_id' => $product->id,
            'product_variation_id' => $variation?->id,
            'message' => __('messages.order.insufficient_stock', ['item' => $variation?->label() ?? $product->name]),
        ] : null;

        return [$unitPrice * $qty, $unitWeight * $qty, $warning];
    }

    /** Prices one order line, reserves its stock, records its fee/commission snapshot. Returns [subtotal, weightGrams]. */
    private function priceAndReserveLine(Order $order, int $agentId, User $konsumen, User $actor, array $line): array
    {
        $product = Product::query()->where('status', 'active')->findOrFail($line['product_id']);
        $qty = (int) $line['quantity'];

        if ($qty < 1) {
            throw new ApiException(__('messages.order.invalid_quantity'), 422);
        }

        $variation = null;

        if ($product->has_variations) {
            if (empty($line['product_variation_id'])) {
                throw new ApiException(__('messages.product.variation_required', ['name' => $product->name]), 422);
            }

            $variation = ProductVariation::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->findOrFail($line['product_variation_id']);

            $unitPrice = (float) $variation->price;
            $unitWeight = (int) ($variation->weight_grams ?: $product->weight_grams);
        } else {
            $unitPrice = (float) $product->base_price;
            $unitWeight = (int) $product->weight_grams;
        }

        if ($unitWeight < 1) {
            throw new ApiException('Berat produk belum dikonfigurasi.', 422, ['items' => 'Berat produk wajib minimal 1 gram.']);
        }

        if ($variation) {
            $this->stockService->reserveForVariation($agentId, $variation, $qty, 'order', $order->id, $actor->id);
        } else {
            $this->stockService->reserveForProduct($agentId, $product, $qty, 'order', $order->id, $actor->id);
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
            // Per-item delivery schedule defaults to the order's own estimate —
            // independently reschedulable per item afterwards (see
            // OrderFulfillmentService::rescheduleItemDeliveryDate).
            'requested_delivery_date' => $order->delivery_date_estimate,
            'status' => $order->status,
        ]);

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

        return [$lineSubtotal, $unitWeight * $qty];
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

        if (! $order->canTransitionTo($newStatus)) {
            throw new InvalidStateTransitionException($order->status, $newStatus);
        }

        // "MANUAL TRANSFER: Order awal diterima ... jika verified: order dapat
        // masuk diproses. Jika belum verified: tetap diterima." Same rule for
        // gateway payments (a webhook, not this endpoint, marks those paid).
        // COD is exempt — "Order COD dapat langsung diproses". A DP order may
        // start processing once its DP has been verified (partially_paid); the
        // outstanding balance is settled later in the transaction.
        $isCod = $order->paymentMethod?->type === 'cod';
        $paymentReady = $order->payment_status === 'paid'
            || ($order->paymentMethod?->code === 'down_payment' && $order->payment_status === 'partially_paid');

        if ($newStatus === 'diproses' && ! $isCod && ! $paymentReady) {
            throw new ApiException(__('messages.order.payment_not_verified'), 422);
        }

        return DB::transaction(function () use ($order, $newStatus, $actor) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $order->status;
            $order->update(['status' => $newStatus]);

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
        if (! $order->canTransitionTo('dibatalkan')) {
            throw new InvalidStateTransitionException($order->status, 'dibatalkan');
        }

        $isCod = $order->paymentMethod?->type === 'cod';
        $allowedStatuses = $isCod ? ['diterima', 'diproses'] : ['diterima'];

        if (! in_array($order->status, $allowedStatuses, true)) {
            throw new InvalidStateTransitionException($order->status, 'dibatalkan');
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            foreach ($order->items as $item) {
                if (! $item->canTransitionTo('dibatalkan')) {
                    continue;
                }

                $remaining = $item->fulfilled_quantity - $item->cancelled_quantity - $item->returned_quantity;

                if ($remaining > 0) {
                    if ($item->product_variation_id) {
                        $this->stockService->releaseVariation(
                            $order->agent_id, $item->product_variation_id, $remaining,
                            'order', $order->id, $actor->id
                        );
                    } else {
                        $this->stockService->releaseProduct(
                            $order->agent_id, $item->product_id, $remaining,
                            'order', $order->id, $actor->id
                        );
                    }
                }

                $item->update(['status' => 'dibatalkan']);
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
