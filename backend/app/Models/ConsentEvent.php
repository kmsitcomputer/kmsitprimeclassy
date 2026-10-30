<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only consent stream. UPDATE and DELETE are refused at the model boundary. */
class ConsentEvent extends Model
{
    public const CAPTURED = 'consent_captured';

    public const WITHDRAWN = 'consent_withdrawn';

    public const DISPUTED = 'consent_disputed';

    public const REINSTATED = 'consent_reinstated';

    protected $fillable = [
        'agent_id', 'subject_user_id', 'crm_customer_id', 'order_id', 'return_id', 'root_event_id', 'event_type', 'purpose',
        'scope', 'evidence_path', 'evidence_sha256', 'note', 'otp_challenge_id', 'actor_id',
        'consent_key', 'policy_version', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);

        static::updating(function (): void {
            throw new \RuntimeException('consent_events is append-only (UPDATE refused).');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('consent_events is append-only (DELETE refused).');
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
