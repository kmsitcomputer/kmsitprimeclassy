<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsHomepageBlockTranslation extends Model
{
    protected $fillable = ['cms_homepage_block_id', 'language_id', 'title', 'subtitle', 'body'];

    public function block(): BelongsTo
    {
        return $this->belongsTo(CmsHomepageBlock::class, 'cms_homepage_block_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
