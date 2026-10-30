<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\CrmCustomer;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderService;

/**
 * G17-D3: Assisted Order from a CRM customer. This service only decides WHO may place it and builds the
 * attribution carrier; pricing, stock, fees, snapshots, shipments and idempotency are the unchanged
 * OrderService::createOrder pipeline.
 *
 * Contract (ARCHITECTURE §16b): the CRM customer is the buyer origin (`crm_customer_id`, `konsumen_id` NULL);
 * `placed_by_user_id` is whoever submitted it; the commission chain is the OWNING seller's
 * (sales_id = owner, korsal_id = owner's korsal) no matter who placed it. No konsumen identity is
 * created, borrowed or linked here, and Agent stock only (Sub stock is not part of the approved scope).
 */
class AssistedOrderService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CrmAccess $access,
    ) {}

    /**
     * @param  array<int, array{product_id:int, product_variation_id?:?int, quantity:int}>  $lines
     * @param  array<string, mixed>  $destination  explicit address fields (the CRM customer has no address book)
     */
    public function place(
        User $actor,
        CrmCustomer $customer,
        array $lines,
        array $destination,
        string $idempotencyKey,
        string $paymentMethodCode,
        ?string $deliveryDate = null,
        ?string $shippingMethod = null,
        ?float $dpAmount = null,
        ?array $selectedCourierOption = null,
        ?string $voucherCode = null,
    ): Order {
        if (! Order::supportsBuyerOrigin()) {
            // The additive migration has not been applied on this database yet (Owner-gated).
            throw new ApiException(__('messages.crm.migration_pending'), 503);
        }

        if ($customer->archived_at !== null) {
            throw new ApiException(__('messages.crm.customer_archived'), 422);
        }

        if (! $this->access->canPlaceOrder($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $owner = User::query()->find($customer->owner_user_id);
        if (! $owner || ! $owner->isRole('sales', 'sales-kurir-sub', 'korsal') || $owner->agent_id !== $customer->agent_id) {
            throw new ApiException(__('messages.crm.owner_invalid'), 422);
        }

        // Transient, NEVER saved: carries only the owning seller's attribution chain into the shared pipeline.
        $carrier = new User;
        $carrier->forceFill([
            'name' => $customer->name,
            'phone' => $customer->phone,
            'agent_id' => $owner->agent_id,
            'sales_id' => $owner->id,
            'korsal_id' => $owner->korsal_id,
        ]);

        return $this->orders->createOrder(
            $carrier,
            $lines,
            $destination,
            $actor,
            $paymentMethodCode,
            $deliveryDate,
            $idempotencyKey,
            $shippingMethod,
            $dpAmount,
            $selectedCourierOption,
            null,
            null,
            $voucherCode,
            $customer,
        );
    }
}
