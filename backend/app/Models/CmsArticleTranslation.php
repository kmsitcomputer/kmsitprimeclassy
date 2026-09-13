<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsArticleTranslation extends Model
{
    // Matches the migration's actual table name (create_cms_articles_translations_table) —
    // Eloquent's naming convention alone would guess 'cms_article_translations' (singular "article").
    protected $table = 'cms_articles_translations';

    protected $fillable = ['cms_article_id', 'language_id', 'title', 'excerpt', 'body'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(CmsArticle::class, 'cms_article_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
