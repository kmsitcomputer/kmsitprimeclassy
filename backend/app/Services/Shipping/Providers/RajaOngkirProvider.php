<?php

namespace App\Services\Shipping\Providers;

use App\Contracts\Shipping\ShippingCostProviderInterface;
use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentShippingProviderConfig;
use App\Models\ShippingCourier;
use App\Models\ShippingProvider;
use App\Models\Village;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** RajaOngkir/Komerce Shipping Cost API V2 adapter. */
class RajaOngkirProvider implements ShippingCostProviderInterface
{
    private const BASE_URL = 'https://rajaongkir.komerce.id/api/v1';

    private const RATE_TTL = 300;

    private const DESTINATION_TTL = 2592000;

    public function quote(ShippingQuoteContext $context, ShippingProvider $providerRow): ShippingQuoteResult
    {
        $options = $this->options($context, $providerRow);
        $selected = $context->selectedCourierOption
            ? collect($options)->first(fn (array $o) => $o['courier'] === strtolower($context->selectedCourierOption['courier']) && $o['service'] === $context->selectedCourierOption['service'])
            : $options[0];

        if (! $selected) {
            throw new ShippingQuoteException('Selected RajaOngkir courier service is unavailable.');
        }

        return new ShippingQuoteResult((float) $selected['value'], null, null, 'rajaongkir', [
            'api_version' => 'komerce_v2', 'courier' => $selected['courier'],
            'courier_name' => $selected['courier_name'], 'service' => $selected['service'],
            'description' => $selected['description'], 'etd' => $selected['etd'],
            'origin_id' => $selected['origin_id'], 'origin_label' => $selected['origin_label'],
            'destination_id' => $selected['destination_id'], 'destination_label' => $selected['destination_label'],
            'weight_grams' => $context->weightGrams,
        ]);
    }

    public function listOptions(ShippingQuoteContext $context, ShippingProvider $providerRow): array
    {
        return $this->options($context, $providerRow);
    }

    public function searchForAgent(int $agentId, ShippingProvider $provider, string $search, ?string $apiKey = null): array
    {
        return $this->searchDestinations($apiKey ?: ($this->config($agentId, $provider)['api_key'] ?? ''), $search);
    }

    public function validateOriginSelection(string $apiKey, string $search, string $originId): array
    {
        $origin = collect($this->searchDestinations($apiKey, $search))->firstWhere('id', $originId);
        if (! $origin) {
            throw new ShippingQuoteException('Origin RajaOngkir tidak cocok dengan hasil pencarian resmi.');
        }

        return $origin;
    }

    public function testForAgent(int $agentId, ShippingProvider $provider): array
    {
        $config = $this->validatedConfig($agentId, $provider);
        $results = $this->searchDestinations($config['api_key'], $config['origin_search'] ?? $config['origin_label']);
        $origin = collect($results)->firstWhere('id', (string) $config['origin_destination_id']);
        if (! $origin) {
            throw new ShippingQuoteException('Configured RajaOngkir origin is not valid.');
        }

        return ['connected' => true, 'api_version' => 'komerce_v2', 'origin_id' => $origin['id'], 'origin_label' => $origin['label']];
    }

    private function options(ShippingQuoteContext $context, ShippingProvider $provider): array
    {
        if ($context->weightGrams < 1) {
            throw new ShippingQuoteException('Shipping weight must be at least one gram.');
        }
        $config = $this->validatedConfig($context->agentId, $provider);
        [$destinationId, $destinationLabel] = $this->destination($context, $config['api_key']);
        $originId = (string) $config['origin_destination_id'];

        // AVAILABLE = provider-SUPPORTED (Super Admin's shipping_couriers
        // master list) ∩ agen-ENABLED (their own saved config.couriers) —
        // enforced here, not just at save time, so a courier Super Admin
        // later deactivates (or an old config row saved before this master
        // list existed) never reaches Komerce or checkout either.
        $agentEnabled = array_unique(array_map('strtolower', $config['couriers']));
        $supported = ShippingCourier::query()->where('is_active', true)->pluck('code')
            ->map(fn ($c) => strtolower($c))->all();
        $couriers = array_values(array_intersect($agentEnabled, $supported));

        if (empty($couriers)) {
            throw new ShippingQuoteException('No supported courier is enabled for this agen.');
        }
        $key = 'rajaongkir:v2:rate:'.hash('sha256', implode('|', [$originId, $destinationId, $context->weightGrams, implode(':', $couriers)]));

        return Cache::remember($key, self::RATE_TTL, function () use ($config, $originId, $destinationId, $destinationLabel, $context, $couriers) {
            $started = microtime(true);
            try {
                $response = Http::withHeaders(['key' => $config['api_key']])->asForm()->timeout(12)
                    ->post(self::BASE_URL.'/calculate/domestic-cost', [
                        'origin' => $originId, 'destination' => $destinationId,
                        'weight' => $context->weightGrams, 'courier' => implode(':', $couriers), 'price' => 'lowest',
                    ]);
            } catch (ConnectionException) {
                $this->logRequest('failed', $context, $originId, $destinationId, $couriers, null, $started, 'connection_error');
                throw new ShippingQuoteException('RajaOngkir tidak dapat dihubungi. Coba lagi beberapa saat.');
            }
            if (! $response->successful()) {
                $this->logRequest('failed', $context, $originId, $destinationId, $couriers, $response->status(), $started, 'provider_error');
                throw new ShippingQuoteException('RajaOngkir menolak permintaan ongkir. Periksa konfigurasi dan tujuan.');
            }

            $options = collect($response->json('data', []))->map(fn (array $row) => [
                'value' => (float) ($row['cost'] ?? 0), 'courier' => strtolower((string) ($row['code'] ?? '')),
                'courier_name' => (string) ($row['name'] ?? $row['code'] ?? ''), 'service' => (string) ($row['service'] ?? ''),
                'description' => $row['description'] ?? null, 'etd' => isset($row['etd']) ? (string) $row['etd'] : null,
                'origin_id' => $originId, 'origin_label' => (string) $config['origin_label'],
                'destination_id' => $destinationId, 'destination_label' => $destinationLabel,
            ])->filter(fn (array $row) => $row['value'] > 0 && $row['courier'] && $row['service'])->sortBy('value')->values()->all();
            $this->logRequest($options ? 'success' : 'failed', $context, $originId, $destinationId, $couriers, $response->status(), $started, $options ? null : 'no_services');
            if (! $options) {
                throw new ShippingQuoteException('RajaOngkir tidak menyediakan layanan untuk rute ini.');
            }

            return $options;
        });
    }

    private function destination(ShippingQuoteContext $context, string $apiKey): array
    {
        if (! $context->destVillageId) {
            throw new ShippingQuoteException('Destination village is required for RajaOngkir.');
        }

        return Cache::remember('rajaongkir:v2:destination:'.$context->destVillageId, self::DESTINATION_TTL, function () use ($context, $apiKey) {
            $village = Village::with('district.regency.province')->find($context->destVillageId);
            if (! $village?->district?->regency?->province) {
                throw new ShippingQuoteException('Destination hierarchy is incomplete.');
            }
            $district = $village->district;
            $regency = $district->regency;
            $match = collect($this->searchDestinations($apiKey, $village->name))->first(
                fn (array $row) => $this->same($row['subdistrict_name'], $village->name) && $this->same($row['district_name'], $district->name)
                    && $this->sameRegency($row['city_name'], $regency->name) && $this->same($row['province_name'], $regency->province->name)
            );
            if (! $match) {
                throw new ShippingQuoteException('Alamat tujuan tidak memiliki mapping RajaOngkir yang cocok.');
            }

            return [(string) $match['id'], $match['label']];
        });
    }

    private function searchDestinations(string $apiKey, string $search): array
    {
        if (! $apiKey || ! trim($search)) {
            throw new ShippingQuoteException('RajaOngkir credential or destination search is missing.');
        }
        try {
            $response = Http::withHeaders(['key' => $apiKey])->timeout(12)->get(self::BASE_URL.'/destination/domestic-destination', ['search' => trim($search), 'limit' => 100, 'offset' => 0]);
        } catch (ConnectionException) {
            throw new ShippingQuoteException('RajaOngkir tidak dapat dihubungi. Coba lagi beberapa saat.');
        }
        if (! $response->successful()) {
            throw new ShippingQuoteException('Koneksi atau pencarian destination RajaOngkir gagal.');
        }

        return collect($response->json('data', []))->map(fn (array $row) => [
            'id' => (string) ($row['id'] ?? ''), 'label' => (string) ($row['label'] ?? ''),
            'province_name' => $row['province_name'] ?? null, 'city_name' => $row['city_name'] ?? null,
            'district_name' => $row['district_name'] ?? null, 'subdistrict_name' => $row['subdistrict_name'] ?? null,
            'zip_code' => isset($row['zip_code']) ? (string) $row['zip_code'] : null,
        ])->filter(fn (array $row) => $row['id'] && $row['label'])->values()->all();
    }

    private function validatedConfig(int $agentId, ShippingProvider $provider): array
    {
        $config = $this->config($agentId, $provider);
        foreach (['api_key', 'origin_destination_id', 'origin_label', 'couriers'] as $field) {
            if (empty($config[$field])) {
                throw new ShippingQuoteException("RajaOngkir configuration is missing {$field}.");
            }
        }

        return $config;
    }

    private function config(int $agentId, ShippingProvider $provider): array
    {
        try {
            return AgentShippingProviderConfig::where('agent_id', $agentId)->where('shipping_provider_id', $provider->id)->value('config') ?? [];
        } catch (DecryptException) {
            Log::warning('shipping.rajaongkir_config_undecryptable', ['agent_id' => $agentId]);
            throw new ShippingQuoteException('RajaOngkir configuration cannot be decrypted.');
        }
    }

    private function same(?string $a, ?string $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }

    private function sameRegency(?string $external, ?string $internal): bool
    {
        return $this->same($external, preg_replace('/^(kota|kabupaten)\s+/i', '', (string) $internal));
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    private function logRequest(string $status, ShippingQuoteContext $context, string $origin, string $destination, array $couriers, ?int $httpStatus, float $started, ?string $error): void
    {
        Log::info('shipping.rajaongkir_quote', [
            'provider' => 'rajaongkir', 'api_version' => 'komerce_v2', 'agent_id' => $context->agentId,
            'origin_id' => $origin, 'destination_id' => $destination, 'weight_grams' => $context->weightGrams,
            'couriers' => $couriers, 'status' => $status, 'http_status' => $httpStatus,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error,
        ]);
    }
}
