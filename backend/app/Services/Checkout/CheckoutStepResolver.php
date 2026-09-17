<?php

namespace App\Services\Checkout;

use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Payment\AvailablePaymentMethodService;
use App\Services\Shipping\ShippingQuoteService;
use App\Support\CheckoutSteps;
use App\Support\SafeSchema;

/**
 * Decides which checkout steps are active for the current request. This is
 * the single source of truth the frontend wizard renders from — it never
 * hard-codes its own step list (Blueprint requirement: "Step dapat berubah
 * berdasarkan kondisi").
 */
class CheckoutStepResolver
{
    public function __construct(
        private readonly ShippingQuoteService $shippingQuoteService,
        private readonly AvailablePaymentMethodService $availablePaymentMethodService,
    ) {}

    /**
     * $shippingMethod narrows `payment_methods` to whatever is actually
     * compatible once the konsumen has picked a shipping method (e.g.
     * Ekspedisi -> Manual Transfer only) — see AvailablePaymentMethodService.
     * Omitted (still on an earlier wizard step, shipping not chosen yet)
     * means "every method this agent has enabled," unrestricted.
     *
     * @return array{steps: array<int, array{key:string,label_key:string,active:bool}>, on_behalf_of_konsumen: bool, shipping_enabled: bool, map_picker_enabled: bool, payment_methods: array}
     */
    public function resolve(?User $actor, ?string $shippingMethod = null): array
    {
        $isAuthenticated = (bool) $actor;
        $onBehalfOfKonsumen = $isAuthenticated && ! $actor->isRole('konsumen');
        // The actor's own branch (every linked role, including agen itself —
        // whose agent_id equals their own id) — null for a guest/super_admin,
        // which simply skips every agent-scoped override below.
        $agentId = $actor?->agent_id;
        // "Available to choose" now includes Pickup (needs no delivery
        // provider at all — see ShippingQuoteService::isPickupAvailable),
        // not just an active RajaOngkir/OpenRoute config.
        $shippingEnabled = $this->shippingQuoteService->isShippingSelectionAvailable($agentId);

        $activeMap = [
            CheckoutSteps::ACCOUNT => ! $isAuthenticated,
            CheckoutSteps::ADDRESS => true,
            CheckoutSteps::REFERRAL => true,
            CheckoutSteps::PRODUCTS => true,
            CheckoutSteps::SHIPPING => $shippingEnabled,
            CheckoutSteps::DELIVERY_DATE => true,
            CheckoutSteps::PAYMENT => true,
            CheckoutSteps::REVIEW => true,
            CheckoutSteps::CONFIRMATION => true,
        ];

        $steps = array_map(
            fn (string $key) => [
                'key' => $key,
                'label_key' => CheckoutSteps::labelKey($key),
                'active' => $activeMap[$key],
            ],
            CheckoutSteps::order()
        );

        return [
            'steps' => $steps,
            // The "referral" step becomes "pick which konsumen" instead of a
            // read-only display of the actor's own chain (see GET /users).
            'on_behalf_of_konsumen' => $onBehalfOfKonsumen,
            'shipping_enabled' => $shippingEnabled,
            // "Jika OpenRoute Aktif, input alamat kirim juga ada form untuk
            // input picker lokasi menggunakan maps" — the frontend only shows
            // the Google Maps picker/autocomplete when this is true.
            'map_picker_enabled' => $this->shippingQuoteService->isOpenRouteEnabled($agentId),
            // "Ekspedisi" (RajaOngkir) / "Kurir Online" (OpenRoute) / "Pickup"
            // — the konsumen picks between these only when more than one is
            // active.
            'shipping_methods' => $this->shippingQuoteService->availableShippingMethods($agentId),
            // Where "Pickup" actually is — the order's own agent's store
            // (AgentProfile, the same source OpenRoute already uses as its
            // origin coordinate). Google Maps renders this for the customer;
            // never used for a shipping-cost calculation (Pickup is always
            // Rp0 — see ShippingQuoteService::quote()).
            'pickup_location' => $agentId ? $this->pickupLocation($agentId) : null,
            // Public — reachable on a completely fresh, unmigrated deployment
            // (installer wizard page, bots, monitoring) before
            // `payment_methods` exists. SafeSchema treats that identically
            // to "no DB connection at all"; no method is configured yet
            // either way, so an empty list here is correct — never a raw
            // QueryException (see SafeSchema's docblock).
            'payment_methods' => SafeSchema::hasTable('payment_methods')
                ? $this->availablePaymentMethodService->availableMethods($agentId, $shippingMethod)
                    ->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'type' => $m->type])
                    ->values()
                    ->toArray()
                : [],
        ];
    }

    /** @return array{store_name:?string, address:?string, latitude:?float, longitude:?float, phone:?string}|null */
    private function pickupLocation(int $agentId): ?array
    {
        if (! SafeSchema::hasTable('agent_profiles')) {
            return null;
        }

        $profile = AgentProfile::query()->where('user_id', $agentId)->first();

        if (! $profile) {
            return null;
        }

        return [
            'store_name' => $profile->store_name,
            'address' => $profile->address,
            'latitude' => $profile->latitude !== null ? (float) $profile->latitude : null,
            'longitude' => $profile->longitude !== null ? (float) $profile->longitude : null,
            'phone' => $profile->phone,
        ];
    }
}
