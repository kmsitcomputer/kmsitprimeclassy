<?php

namespace App\Services\Shipping\Providers;

use App\DataTransferObjects\OpenRouteResult;
use App\DataTransferObjects\ShippingQuoteContext;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\AgentShippingProviderSetting;
use App\Models\ShippingProvider;
use App\Services\Shipping\KmsitDistanceCalculator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * KMSIT Routing Server (OSRM) — a transparent routing-distance fallback for Kurir Toko, NOT a customer-facing method
 * and NOT a pricing policy: it only supplies verified distance/duration; OpenRouteProvider applies the one tariff.
 */
class KmsitProvider
{
    private const DISTANCE_CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly KmsitDistanceCalculator $calculator) {}

    /** Globally active, not switched off by this Agen, and an endpoint saved. */
    public function isEligible(?int $agentId): bool
    {
        if (! $agentId) {
            return false;
        }
        $row = ShippingProvider::query()->where('code', 'kmsit')->first();
        if (! $row?->is_active) {
            return false;
        }
        if (AgentShippingProviderSetting::query()->where('agent_id', $agentId)->where('shipping_provider_id', $row->id)->where('is_active', false)->exists()) {
            return false;
        }

        return AgentShippingProviderConfig::query()->where('agent_id', $agentId)->where('shipping_provider_id', $row->id)->exists();
    }

    public function route(ShippingQuoteContext $context): OpenRouteResult
    {
        $row = ShippingProvider::query()->where('code', 'kmsit')->first();
        if (! $context->agentId || ! $row) {
            throw new ShippingQuoteException('KMSIT requires an agen context.');
        }
        $config = $this->resolveConfig($context->agentId, $row);
        if (empty($config['base_url'])) {
            throw new ShippingQuoteException('KMSIT is not configured (base_url missing).');
        }

        // Per-Agen + endpoint identity + profile: one Agen's server or results can never answer another's quote.
        $key = 'kmsit:route:'.$context->agentId.':'.substr(hash('sha256', (string) $config['base_url']), 0, 16).':'.\App\Services\Shipping\KmsitDistanceCalculator::normalizeProfile($config['profile'] ?? null).':'
            .round($context->originLat, 5).','.round($context->originLng, 5).'-'.round($context->destLat, 5).','.round($context->destLng, 5);

        return Cache::remember($key, self::DISTANCE_CACHE_TTL_SECONDS, fn () => $this->calculator->route(
            $context->originLat, $context->originLng, $context->destLat, $context->destLng, $config
        ));
    }

    public function testForAgent(int $agentId, float $destLat, float $destLng): array
    {
        $row = ShippingProvider::query()->where('code', 'kmsit')->first();
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $row || ! $profile) {
            throw new ShippingQuoteException('Lokasi origin Agent belum dikonfigurasi.');
        }
        $route = $this->calculator->route((float) $profile->latitude, (float) $profile->longitude, $destLat, $destLng, $this->resolveConfig($agentId, $row));

        return ['connected' => true, 'profile' => $route->profile, 'distance_km' => $route->distanceKm, 'duration_seconds' => $route->durationSeconds];
    }

    /** @return array{base_url?:string,profile?:string,token?:string} */
    private function resolveConfig(int $agentId, ShippingProvider $row): array
    {
        try {
            return AgentShippingProviderConfig::query()->where('agent_id', $agentId)->where('shipping_provider_id', $row->id)->value('config') ?? [];
        } catch (DecryptException) {
            Log::warning('shipping.kmsit_config_undecryptable', ['agent_id' => $agentId]);

            throw new ShippingQuoteException('KMSIT config could not be decrypted — ask the agent to re-save it.');
        }
    }
}
