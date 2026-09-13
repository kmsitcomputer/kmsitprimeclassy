<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsArticle;
use App\Models\CmsArticleTranslation;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Services\Sanitizer\HtmlSanitizerService;
use Illuminate\Support\Facades\DB;

/**
 * Articles and news share this one service (and one table — see the
 * cms_articles migration docblock), differentiated only by `type`. Cover
 * images arrive as a Media id already uploaded via POST /media (collection
 * 'cms_article_cover') — never a raw file on this endpoint, so the
 * upload/validate/replace concerns stay entirely inside MediaService.
 */
class CmsArticleService
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly HtmlSanitizerService $sanitizer,
    ) {}

    public function create(array $data, int $authorId): CmsArticle
    {
        return DB::transaction(function () use ($data, $authorId) {
            $article = CmsArticle::create([
                'type' => $data['type'],
                'slug' => $data['slug'],
                'author_id' => $authorId,
                'status' => $data['status'] ?? 'draft',
                'published_at' => $data['published_at'] ?? null,
            ]);

            $this->syncCoverImage($article, $data['cover_media_id'] ?? null, false);
            $this->syncTranslations($article, $data['translations']);

            return $article->fresh(['translations', 'author']);
        });
    }

    public function update(CmsArticle $article, array $data): CmsArticle
    {
        return DB::transaction(function () use ($article, $data) {
            $article->update([
                'type' => $data['type'] ?? $article->type,
                'slug' => $data['slug'] ?? $article->slug,
                'status' => $data['status'] ?? $article->status,
                'published_at' => array_key_exists('published_at', $data) ? $data['published_at'] : $article->published_at,
            ]);

            if (array_key_exists('cover_media_id', $data) || ! empty($data['remove_cover'])) {
                $this->syncCoverImage($article, $data['cover_media_id'] ?? null, ! empty($data['remove_cover']));
            }

            if (! empty($data['translations'])) {
                $this->syncTranslations($article, $data['translations']);
            }

            return $article->fresh(['translations', 'author']);
        });
    }

    public function delete(CmsArticle $article): void
    {
        DB::transaction(function () use ($article) {
            $this->deleteCoverIfAny($article);
            $article->delete();
        });
    }

    private function syncCoverImage(CmsArticle $article, ?int $mediaId, bool $remove): void
    {
        if ($remove) {
            $this->deleteCoverIfAny($article);
            $article->update(['cover_image_path' => null]);

            return;
        }

        if ($mediaId === null) {
            return;
        }

        $media = Media::query()->where('collection', 'cms_article_cover')->whereKey($mediaId)->first();

        if (! $media) {
            throw new ApiException(__('messages.media.not_found'), 422);
        }

        $this->deleteCoverIfAny($article);
        $article->update(['cover_image_path' => $media->path]);
        $this->mediaService->attachTo($media, $article);
    }

    private function deleteCoverIfAny(CmsArticle $article): void
    {
        $existing = Media::query()
            ->where('mediable_type', CmsArticle::class)->where('mediable_id', $article->id)
            ->where('collection', 'cms_article_cover')->first();

        if ($existing) {
            $this->mediaService->delete($existing);
        }
    }

    /** @param  array<int, array{language_id:int, title:string, excerpt?:?string, body:string}>  $translations */
    private function syncTranslations(CmsArticle $article, array $translations): void
    {
        foreach ($translations as $translation) {
            CmsArticleTranslation::query()->updateOrCreate(
                ['cms_article_id' => $article->id, 'language_id' => $translation['language_id']],
                [
                    'title' => $translation['title'],
                    'excerpt' => $translation['excerpt'] ?? null,
                    'body' => $this->sanitizer->sanitize($translation['body'] ?? ''),
                ]
            );
        }
    }
}
