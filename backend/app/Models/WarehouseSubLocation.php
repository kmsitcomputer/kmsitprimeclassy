<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseSubLocation extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = ['agent_id', 'code', 'name', 'address', 'contact_number', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'created_by' => 'integer', 'is_active' => 'boolean'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'sub_location_id');
    }
}
