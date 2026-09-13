<?php

namespace App\Support;

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
                'account_type' => ['required', 'in:starter,basic,pro'],
                'origin_city_id' => ['required', 'string', 'max:20'],
                'couriers' => ['required', 'array', 'min:1'],
                'couriers.*' => ['string', 'in:jne,pos,tiki'],
            ],
            'openroute' => [
                'api_key' => ['required', 'string', 'max:255'],
                'base_url' => ['nullable', 'string', 'max:255', 'url'],
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
}
