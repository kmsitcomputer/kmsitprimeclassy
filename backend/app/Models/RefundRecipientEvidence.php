<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** G17-D4: append-only evidence of WHO receives a CRM-origin refund, how, and under what authorization. */
class RefundRecipientEvidence extends Model
{
    public const METHODS = ['bank_transfer', 'ewallet', 'cash'];

    protected $table = 'refund_recipient_evidences';

    protected $fillable = [
        'agent_id', 'order_id', 'crm_customer_id', 'return_id', 'recipient_name', 'recipient_phone',
        'recipient_relation', 'method', 'destination_details', 'consent_event_id', 'authorization_note',
        'evidence_path', 'evidence_sha256', 'supersedes_id', 'captured_by', 'occurred_at',
    ];

    protected $hidden = ['destination_details'];

    protected function casts(): array
    {
        return [
            'destination_details' => 'encrypted:array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);

        static::updating(function (): void {
            throw new \RuntimeException('refund_recipient_evidences is append-only (UPDATE refused).');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('refund_recipient_evidences is append-only (DELETE refused).');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
