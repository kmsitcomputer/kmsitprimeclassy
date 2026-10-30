<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Maps `crm_customers`: a business contact, never a `User` (BUSINESS-RULES §27). */
class CrmCustomer extends Model
{
    public const PROSPECT_PERCENTS = [0, 25, 50, 75, 90, 100];

    protected $fillable = [
        'agent_id', 'owner_user_id', 'name', 'phone', 'email', 'customer_type', 'relation',
        'address', 'prospect_percent', 'note', 'archived_at', 'linked_konsumen_id',
    ];

    protected function casts(): array
    {
        return [
            'prospect_percent' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = PhoneNormalizer::normalize($value);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function linkedKonsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_konsumen_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'crm_customer_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CrmVisit::class);
    }
}
