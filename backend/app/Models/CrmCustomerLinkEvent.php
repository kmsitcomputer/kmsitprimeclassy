<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of CRM customer <-> konsumen links (OD-03/OD-04). UPDATE and DELETE are refused. */
class CrmCustomerLinkEvent extends Model
{
    public const LINKED = 'linked';

    public const UNLINKED = 'unlinked';

    protected $fillable = [
        'agent_id', 'crm_customer_id', 'konsumen_user_id', 'event_type', 'actor_id',
        'otp_challenge_id', 'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);

        static::updating(function (): void {
            throw new \RuntimeException('crm_customer_link_events is append-only (UPDATE refused).');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('crm_customer_link_events is append-only (DELETE refused).');
        });
    }
}
