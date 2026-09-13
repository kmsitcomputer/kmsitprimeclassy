<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsHomepageBlock extends Model
{
    protected $fillable = ['type', 'content', 'sort_order', 'is_active', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CmsHomepageBlockTranslation::class);
    }
}
