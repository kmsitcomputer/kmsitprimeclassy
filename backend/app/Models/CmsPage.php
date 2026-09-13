<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsPage extends Model
{
    protected $fillable = ['slug', 'status', 'published_at', 'cover_image_path'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CmsPageTranslation::class);
    }
}
