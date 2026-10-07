<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IMP-002 — Admin-managed dynamic invoice configuration.
 *
 * Structured JSON config; agent_id = 0 means the super_admin global default,
 * >0 means that agent's own row. Resolution happens in
 * App\Services\Invoice\InvoiceConfigService (per-agent -> global -> built-in
 * defaults). The config is display-oriented only — the PDF's financial truth
 * always derives from OrderTotalCalculator / PaymentSummaryService.
 */
class InvoiceConfig extends Model
{
    protected $fillable = ['agent_id', 'config'];

    protected function casts(): array
    {
        return ['config' => 'array', 'agent_id' => 'integer'];
    }

    /** Sentinel agent_id used for the global/default row. */
    public const GLOBAL_AGENT_ID = 0;
}