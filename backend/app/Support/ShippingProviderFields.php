<?php

namespace App\Support;

use App\Models\ShippingCourier;
use Illuminate\Validation\Rule;

/**
 * Which config fields each shipping provider needs — mirrors
 * PaymentGatewayFields. Every field here is written to a per-agen
 * AgentShippingProviderConfig row (encrypted) and never read back to the frontend.
 */
class ShippingProviderFields
{
    public static function definitions(): array
    {
        return [
            'rajaongkir' => [
                'api_key' => ['required', 'string', 'max:255'],
                'api_version' => ['required', 'in:komerce_v2'],
                'origin_destination_id' => ['required', 'integer', 'min:1'],
                'origin_label' => ['required', 'string', 'max:255'],
                'origin_search' => ['required', 'string', 'max:100'],
                'couriers' => ['required', 'array', 'min:1'],
                // Never trust an arbitrary string here — same provider-
                // supported master list (shipping_couriers) the dedicated
                // couriers checkbox endpoint validates against (see
                // AgentShippingProviderController::updateCouriers), so this
                // full-config form can't bypass that check.
                'couriers.*' => ['string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::in(self::supportedCourierCodes())],
            ],
            'openroute' => [
                'api_key' => ['required', 'string', 'max:255'],
                'profile' => ['required', 'in:driving-car,driving-hgv,cycling-regular,cycling-road,cycling-mountain,cycling-electric,foot-walking,foot-hiking,wheelchair'],
            ],
        ];
    }

    public static function rulesFor(string $code): array
    {
        $fields = self::definitions()[$code] ?? null;

        if ($fields === null) {
            return [];
        }

        $rules = [];
        foreach ($fields as $field => $fieldRules) {
            $rules["config.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::definitions()[$code]);
    }

    /** @return array<int, string> Lowercase codes from the provider-supported courier master list (shipping_couriers, is_active only). */
    private static function supportedCourierCodes(): array
    {
        return ShippingCourier::query()->where('is_active', true)->pluck('code')
            ->map(fn ($c) => strtolower($c))->all();
    }
}
