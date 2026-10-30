<?php

namespace App\Support;

/**
 * Which credential fields each payment method's config needs, and their
 * validation rules — the single place that changes if a gateway's
 * requirements change. Every field here is a credential: AgentPaymentGatewayConfig
 * stores the whole `config` blob encrypted, per-agen, and no controller/resource
 * ever echoes any of it back to the frontend (see AgentPaymentMethodController
 * — it returns `configured: bool`, never the values).
 */
class PaymentGatewayFields
{
    public static function definitions(): array
    {
        return [
            'bank_transfer' => [
                'bank_name' => ['required', 'string', 'max:60'],
                'account_name' => ['required', 'string', 'max:100'],
                'account_number' => ['required', 'string', 'max:40'],
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
