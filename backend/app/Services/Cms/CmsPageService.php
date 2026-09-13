<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageTranslation;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Services\Sanitizer\HtmlSanitizerService;
use Illuminate\Support\Facades\DB;

/**
 * Static pages (About, Terms, ...) — same public/admin split as
 * CmsArticleService, including an optional cover image (collection
 * 'cms_page_cover'), arriving as a Media id already uploaded via
 * POST /media — never a raw file on this endpoint.
 */
class CmsPageService
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly HtmlSanitizerService $sanitizer,
    ) {}

    public function create(array $data): CmsPage
    {
        return DB::transaction(function () use ($data) {
            $page = CmsPage::create([
                'slug' => $data['slug'],
                'status' => $data['status'] ?? 'draft',
                'published_at' => $data['published_at'] ?? null,
            ]);

            $this->syncCoverImage($page, $data['cover_media_id'] ?? null, false);
            $this->syncTranslations($page, $data['translations']);

            return $page->fresh('translations');
        });
    }

    public function update(CmsPage $page, array $data): CmsPage
    {
        return DB::transaction(function () use ($page, $data) {
            $page->update([
                'slug' => $data['slug'] ?? $page->slug,
                'status' => $data['status'] ?? $page->status,
                'published_at' => array_key_exists('published_at', $data) ? $data['published_at'] : $page->published_at,
            ]);

            if (array_key_exists('cover_media_id', $data) || ! empty($data['remove_cover'])) {
                $this->syncCoverImage($page, $data['cover_media_id'] ?? null, ! empty($data['remove_cover']));
            }

            if (! empty($data['translations'])) {
                $this->syncTranslations($page, $data['translations']);
            }

            return $page->fresh('translations');
        });
    }

    public function delete(CmsPage $page): void
    {
        DB::transaction(function () use ($page) {
            $this->deleteCoverIfAny($page);
            $page->delete();
        });
    }

    private function syncCoverImage(CmsPage $page, ?int $mediaId, bool $remove): void
    {
        if ($remove) {
            $this->deleteCoverIfAny($page);
            $page->update(['cover_image_path' => null]);

            return;
        }

        if ($mediaId === null) {
            return;
        }

        $media = Media::query()->where('collection', 'cms_page_cover')->whereKey($mediaId)->first();

        if (! $media) {
            throw new ApiException(__('messages.media.not_found'), 422);
        }

        $this->deleteCoverIfAny($page);
        $page->update(['cover_image_path' => $media->path]);
        $this->mediaService->attachTo($media, $page);
    }

    private function deleteCoverIfAny(CmsPage $page): void
    {
        $existing = Media::query()
            ->where('mediable_type', CmsPage::class)->where('mediable_id', $page->id)
            ->where('collection', 'cms_page_cover')->first();

        if ($existing) {
            $this->mediaService->delete($existing);
        }
    }

    /** @param  array<int, array{language_id:int, title:string, body?:?string, seo_title?:?string, seo_description?:?string}>  $translations */
    private function syncTranslations(CmsPage $page, array $translations): void
    {
        foreach ($translations as $translation) {
            CmsPageTranslation::query()->updateOrCreate(
                ['cms_page_id' => $page->id, 'language_id' => $translation['language_id']],
                [
                    'title' => $translation['title'],
                    'body' => $this->sanitizer->sanitize($translation['body'] ?? ''),
                    'seo_title' => $translation['seo_title'] ?? null,
                    'seo_description' => $translation['seo_description'] ?? null,
                ]
            );
        }
    }
}
