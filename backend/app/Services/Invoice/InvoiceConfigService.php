<?php

namespace App\Services\Invoice;

use App\Models\InvoiceConfig;

/**
 * IMP-002 — dynamic invoice presentation configuration.
 *
 * Resolution order: the agent's OWN row (agent_id > 0) -> the super_admin
 * GLOBAL default row (agent_id = 0) -> built-in defaults. Mirrors the
 * Google Auth config resolution pattern. The config is DISPLAY-only: the
 * PDF's financial truth always comes from OrderTotalCalculator /
 * PaymentSummaryService, never from this config.
 */
class InvoiceConfigService
{
    public const DEFAULTS = [
        'company_name' => 'PrimeClassy',
        'title' => 'INVOICE',
        'contact' => '',
        'address' => '',
        'footer' => 'Terima kasih atas kepercayaan Anda.',
        'notes' => '',
        'show_items' => true,
        'show_konsumen' => true,
        'show_sales' => true,
        'show_korsal' => true,
        'show_payment_summary' => true,
        'show_voucher' => true,
        'logo_media_id' => null,
    ];

    public static function resolved(int $agentId): array
    {
        $agent = InvoiceConfig::query()->where('agent_id', $agentId)->first();
        $global = InvoiceConfig::query()->where('agent_id', InvoiceConfig::GLOBAL_AGENT_ID)->first();

        return array_merge(
            self::DEFAULTS,
            (array) ($global?->config ?? []),
            (array) ($agent?->config ?? []),
        );
    }

    /** For the super_admin editing the global row. */
    public static function global(): array
    {
        $global = InvoiceConfig::query()->where('agent_id', InvoiceConfig::GLOBAL_AGENT_ID)->first();

        return array_merge(self::DEFAULTS, (array) ($global?->config ?? []));
    }

    /** For an agen editing their own branch's row. */
    public static function forAgent(int $agentId): array
    {
        return self::resolved($agentId);
    }

    /** Upsert the config JSON for a scope. Returns the saved array. */
    public static function upsert(int $agentId, array $config): array
    {
        $clean = array_intersect_key($config, self::DEFAULTS);
        $row = InvoiceConfig::query()->firstOrNew(['agent_id' => $agentId]);
        $row->config = $clean;
        $row->save();

        return $agentId === InvoiceConfig::GLOBAL_AGENT_ID ? self::global() : self::resolved($agentId);
    }
}