<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPageTranslation extends Model
{
    // Matches the migration's actual table name (create_cms_pages_translations_table) —
    // Eloquent's naming convention alone would guess 'cms_page_translations' (singular "page").
    protected $table = 'cms_pages_translations';

    protected $fillable = ['cms_page_id', 'language_id', 'title', 'body', 'seo_title', 'seo_description'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
