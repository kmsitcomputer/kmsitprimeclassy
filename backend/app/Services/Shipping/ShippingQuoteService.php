<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ApiException;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentShippingProviderSetting;
use App\Models\ShippingProvider;
use App\Services\Shipping\Providers\OpenRouteProvider;
use App\Services\Shipping\Providers\RajaOngkirProvider;
use App\Support\SafeSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point OrderService/CheckoutController use for shipping.
 *
 * When the konsumen has explicitly picked a shipping method — "Ekspedisi"
 * (RajaOngkir) or "Kurir Online" (OpenRoute), only ever offered once
 * OpenRoute is active alongside RajaOngkir — that exact provider is used and
 * never silently swapped for the other one on failure (the konsumen chose a
 * price/service, not "whichever works"). Without an explicit choice
 * (only one provider active, or an internal/legacy caller), the automatic
 * precedence applies: RajaOngkir first, OpenRoute second, free last —
 * "Jika keduanya disable maka Free Shipping".
 */
class ShippingQuoteService
{
    public function __construct(
        private readonly RajaOngkirProvider $rajaOngkir,
        private readonly OpenRouteProvider $openRoute,
    ) {}

    /** True when a super_admin-disabled provider, OR one the order's own agen has switched off for their branch, must not be quoted. */
    private function agentDisabled(int $providerId, ?int $agentId): bool
    {
        if (! $agentId) {
            return false;
        }

        return AgentShippingProviderSetting::query()
            ->where('agent_id', $agentId)->where('shipping_provider_id', $providerId)->where('is_active', false)->exists();
    }

    public function quote(ShippingQuoteContext $context): ShippingQuoteResult
    {
        if ($context->preferredProvider) {
            return $this->quoteWithPreferredProvider($context);
        }

        $attemptedProvider = false;
        $rajaOngkirRow = ShippingProvider::query()->where('code', 'rajaongkir')->first();

        if ($rajaOngkirRow?->is_active && ! $this->agentDisabled($rajaOngkirRow->id, $context->agentId)) {
            $attemptedProvider = true;
            try {
                return $this->rajaOngkir->quote($context, $rajaOngkirRow);
            } catch (ShippingQuoteException $e) {
                Log::warning('shipping.rajaongkir_quote_failed', ['error' => $e->getMessage()]);
            }
        }

        $openRouteRow = ShippingProvider::query()->where('code', 'openroute')->first();

        if ($openRouteRow?->is_active && ! $this->agentDisabled($openRouteRow->id, $context->agentId)) {
            $attemptedProvider = true;
            try {
                return $this->openRoute->quote($context, $openRouteRow);
            } catch (ShippingQuoteException $e) {
                Log::warning('shipping.openroute_quote_failed', ['error' => $e->getMessage()]);
            }
        }

        if ($attemptedProvider) {
            throw new ApiException(__('messages.shipping.method_not_available'), 422, [
                'shipping_method' => __('messages.system.field_invalid'),
            ]);
        }

        return ShippingQuoteResult::free('no_provider_enabled_or_all_failed');
    }

    /**
     * Every courier/service RajaOngkir currently offers for this route+weight
     * (JNE REG, JNE YES, TIKI REG, ...), cheapest first — what the checkout
     * "pick a courier" sub-step under "Ekspedisi" lists. Only meaningful for
     * RajaOngkir; OpenRoute is a single distance-based calculation with no
     * courier concept at all.
     *
     * @return array<int, array{courier: string, service: string, cost: float, etd: ?string}>
     */
    public function courierOptions(ShippingQuoteContext $context): array
    {
        $providerRow = ShippingProvider::query()->where('code', 'rajaongkir')->first();

        if (! $providerRow?->is_active || $this->agentDisabled($providerRow->id, $context->agentId)) {
            throw new ApiException(__('messages.shipping.method_not_available'), 422, [
                'shipping_method' => __('messages.system.field_invalid'),
            ]);
        }

        try {
            $options = $this->rajaOngkir->listOptions($context, $providerRow);
        } catch (ShippingQuoteException $e) {
            // ShippingQuoteException's own contract: never surfaced raw to
            // the client — same as every other caller of a provider here.
            Log::warning('shipping.courier_options_failed', ['error' => $e->getMessage()]);

            throw new ApiException(__('messages.shipping.method_not_available'), 422, [
                'shipping_method' => __('messages.system.field_invalid'),
            ]);
        }

        return array_map(
            fn (array $o) => ['courier' => $o['courier'], 'service' => $o['service'], 'cost' => $o['value'], 'etd' => $o['etd']],
            $options
        );
    }

    private function quoteWithPreferredProvider(ShippingQuoteContext $context): ShippingQuoteResult
    {
        $providerRow = ShippingProvider::query()->where('code', $context->preferredProvider)->first();

        if (! $providerRow?->is_active || $this->agentDisabled($providerRow->id, $context->agentId)) {
            throw new ApiException(__('messages.shipping.method_not_available'), 422, [
                'shipping_method' => __('messages.system.field_invalid'),
            ]);
        }

        $provider = match ($context->preferredProvider) {
            'rajaongkir' => $this->rajaOngkir,
            'openroute' => $this->openRoute,
            default => throw new ApiException(__('messages.shipping.method_not_available'), 422),
        };

        try {
            return $provider->quote($context, $providerRow);
        } catch (ShippingQuoteException $e) {
            Log::warning('shipping.preferred_provider_quote_failed', [
                'provider' => $context->preferredProvider, 'error' => $e->getMessage(),
            ]);

            throw new ApiException(__('messages.shipping.method_not_available'), 422, [
                'shipping_method' => __('messages.system.field_invalid'),
            ]);
        }
    }

    /**
     * @return Collection<int, ShippingProvider> Globally-active rajaongkir/openroute rows, minus any this agent has switched off for their own branch.
     *
     * Feeds GET /checkout/steps, a public route reachable on a completely
     * fresh, unmigrated deployment (installer wizard page, bots, monitoring)
     * before `shipping_providers` exists. SafeSchema treats that identically
     * to "no DB connection at all"; no provider is configured yet either
     * way, so an empty collection here is correct — never a raw
     * QueryException (see SafeSchema's docblock).
     */
    private function activeProviders(?int $agentId): Collection
    {
        if (! SafeSchema::hasTable('shipping_providers')) {
            return collect();
        }

        $providers = ShippingProvider::query()->whereIn('code', ['rajaongkir', 'openroute'])->where('is_active', true)->get();

        if (! $agentId) {
            return $providers;
        }

        $disabledIds = AgentShippingProviderSetting::query()
            ->where('agent_id', $agentId)->where('is_active', false)
            ->whereIn('shipping_provider_id', $providers->pluck('id'))
            ->pluck('shipping_provider_id');

        return $providers->reject(fn (ShippingProvider $p) => $disabledIds->contains($p->id))->values();
    }

    public function isAnyProviderEnabled(?int $agentId = null): bool
    {
        return $this->activeProviders($agentId)->isNotEmpty();
    }

    public function isOpenRouteEnabled(?int $agentId = null): bool
    {
        return $this->activeProviders($agentId)->contains(fn (ShippingProvider $p) => $p->code === 'openroute');
    }

    /** @return array<int, array{code:string, label:string}> */
    public function availableShippingMethods(?int $agentId = null): array
    {
        $labels = ['rajaongkir' => 'Ekspedisi', 'openroute' => 'Kurir Online'];

        return $this->activeProviders($agentId)
            ->sortBy('code')
            ->map(fn (ShippingProvider $p) => ['code' => $p->code, 'label' => $labels[$p->code]])
            ->values()
            ->all();
    }
}
