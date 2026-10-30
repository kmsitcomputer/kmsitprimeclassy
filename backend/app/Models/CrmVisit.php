<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps `crm_visits`. `status` is the verified SimpleV2 vocabulary only; FU/D are NOT implemented. */
class CrmVisit extends Model
{
    public const STATUSES = ['N', '1', '2', '3', '4', '5', '6', '7'];

    protected $fillable = [
        'agent_id', 'crm_customer_id', 'user_id', 'visit_date', 'status', 'prospect_percent', 'note', 'plan_next_date',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'plan_next_date' => 'date',
            'prospect_percent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
