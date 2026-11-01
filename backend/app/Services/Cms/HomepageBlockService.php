<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsHomepageBlock;
use App\Services\Sanitizer\HtmlSanitizerService;
use App\Support\HomepageBlockTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only place CmsHomepageBlock rows and their uploaded images are
 * written. Images always arrive as real uploaded files (see
 * StoreHomepageBlockRequest's `image` rule) — an admin can never type a URL
 * in as a substitute for uploading one.
 */
class HomepageBlockService
{
    private const DISK = 'public';

    private const IMAGE_DIRECTORY = 'cms/homepage-blocks';

    public function __construct(private readonly HtmlSanitizerService $sanitizer) {}

    /** @param  array<string, array{desktop?: UploadedFile, mobile?: UploadedFile}>  $slideImages  uploads keyed by slide key */
    public function create(string $type, array $content, array $meta, ?UploadedFile $image, array $slideImages = []): CmsHomepageBlock
    {
        if (! in_array($type, HomepageBlockTypes::slugs(), true)) {
            throw new ApiException(__('messages.cms.unknown_type', ['type' => $type]), 422, ['type' => __('messages.system.field_invalid')]);
        }

        return DB::transaction(function () use ($type, $content, $meta, $image, $slideImages) {
            $content = $this->sanitizeContent($type, $content);
            if ($type === HomepageBlockTypes::BANNER_SLIDER) {
                $content['slides'] = $this->applySlides($content['slides'] ?? [], [], $slideImages);
            }

            if ($image) {
                if (! HomepageBlockTypes::acceptsImage($type)) {
                    throw new ApiException(__('messages.cms.type_rejects_image', ['type' => $type]), 422);
                }

                $content['image_path'] = $image->store(self::IMAGE_DIRECTORY, self::DISK);
            }

            $nextSortOrder = CmsHomepageBlock::query()->max('sort_order') + 1;

            return CmsHomepageBlock::create([
                'type' => $type,
                'content' => $content,
                'sort_order' => $meta['sort_order'] ?? $nextSortOrder,
                'is_active' => $meta['is_active'] ?? true,
                'starts_at' => $meta['starts_at'] ?? null,
                'ends_at' => $meta['ends_at'] ?? null,
            ]);
        });
    }

    /** @param  array<string, array{desktop?: UploadedFile, mobile?: UploadedFile}>  $slideImages  uploads keyed by slide key */
    public function update(CmsHomepageBlock $block, array $content, array $meta, ?UploadedFile $image, bool $removeImage, array $slideImages = []): CmsHomepageBlock
    {
        return DB::transaction(function () use ($block, $content, $meta, $image, $removeImage, $slideImages) {
            $content = $this->sanitizeContent($block->type, $content, $block->content ?? []);
            if ($block->type === HomepageBlockTypes::BANNER_SLIDER) {
                $content['slides'] = $this->applySlides($content['slides'] ?? [], $block->content['slides'] ?? [], $slideImages);
            }

            if ($image) {
                if (! HomepageBlockTypes::acceptsImage($block->type)) {
                    throw new ApiException(__('messages.cms.type_rejects_image', ['type' => $block->type]), 422);
                }

                $this->deleteImageIfAny($block);
                $content['image_path'] = $image->store(self::IMAGE_DIRECTORY, self::DISK);
            } elseif ($removeImage) {
                $this->deleteImageIfAny($block);
                unset($content['image_path']);
            } elseif (array_key_exists('image_path', $block->content ?? [])) {
                // Neither replaced nor explicitly removed — keep the existing file.
                $content['image_path'] = $block->content['image_path'];
            }

            $block->update([
                'content' => $content,
                'sort_order' => $meta['sort_order'] ?? $block->sort_order,
                'is_active' => $meta['is_active'] ?? $block->is_active,
                'starts_at' => array_key_exists('starts_at', $meta) ? $meta['starts_at'] : $block->starts_at,
                'ends_at' => array_key_exists('ends_at', $meta) ? $meta['ends_at'] : $block->ends_at,
            ]);

            return $block->fresh();
        });
    }

    public function delete(CmsHomepageBlock $block): void
    {
        DB::transaction(function () use ($block) {
            $this->deleteImageIfAny($block);
            foreach ($block->content['slides'] ?? [] as $slide) {
                $this->deletePath($slide['image_path'] ?? null);
                $this->deletePath($slide['mobile_image_path'] ?? null);
            }
            $block->delete();
        });
    }

    public function toggle(CmsHomepageBlock $block): CmsHomepageBlock
    {
        $block->update(['is_active' => ! $block->is_active]);

        return $block->fresh();
    }

    /** @param  int[]  $orderedBlockIds  block IDs in their new display order */
    public function reorder(array $orderedBlockIds): void
    {
        DB::transaction(function () use ($orderedBlockIds) {
            foreach (array_values($orderedBlockIds) as $index => $blockId) {
                CmsHomepageBlock::query()->whereKey($blockId)->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * Builds the stored slide list. A client never supplies storage paths: a slide's images come only from
     * (a) an upload sent for its `key`, or (b) the file already stored for that same `key`. Replaced or
     * removed files and files of deleted slides are deleted; the incoming array order is the slide order.
     *
     * @param  array<int, array<string, mixed>>  $incoming
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<string, array{desktop?: UploadedFile, mobile?: UploadedFile}>  $uploads
     * @return array<int, array<string, mixed>>
     */
    private function applySlides(array $incoming, array $existing, array $uploads): array
    {
        $previous = [];
        foreach ($existing as $slide) {
            if (isset($slide['key'])) {
                $previous[$slide['key']] = $slide;
            }
        }

        $result = [];
        $errors = [];
        foreach (array_values($incoming) as $index => $slide) {
            $key = (string) $slide['key'];
            $old = $previous[$key] ?? [];
            $files = $uploads[$key] ?? [];

            $clean = [
                'key' => $key,
                'title' => $this->nullableText($slide['title'] ?? null),
                'description' => $this->nullableText($slide['description'] ?? null),
                'alt' => $this->nullableText($slide['alt'] ?? null),
                'button_label' => $this->nullableText($slide['button_label'] ?? null),
                'button_url' => $this->nullableText($slide['button_url'] ?? null),
                'overlay' => $slide['overlay'] ?? 'dark',
                'content_position' => $slide['content_position'] ?? 'left',
                'image_path' => $old['image_path'] ?? null,
                'mobile_image_path' => $old['mobile_image_path'] ?? null,
            ];

            if (($files['desktop'] ?? null) instanceof UploadedFile) {
                $this->deletePath($clean['image_path']);
                $clean['image_path'] = $files['desktop']->store(self::IMAGE_DIRECTORY, self::DISK);
            }
            if (($files['mobile'] ?? null) instanceof UploadedFile) {
                $this->deletePath($clean['mobile_image_path']);
                $clean['mobile_image_path'] = $files['mobile']->store(self::IMAGE_DIRECTORY, self::DISK);
            } elseif (filter_var($slide['remove_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->deletePath($clean['mobile_image_path']);
                $clean['mobile_image_path'] = null;
            }

            if (! $clean['image_path']) {
                $errors['content.slides.'.$index.'.image'] = __('messages.cms.slide_image_required');
            }

            $result[] = $clean;
        }

        if ($errors) {
            throw new ApiException(__('messages.cms.slide_image_required'), 422, $errors);
        }

        $kept = array_column($result, 'key');
        foreach ($previous as $key => $slide) {
            if (! in_array($key, $kept, true)) {
                $this->deletePath($slide['image_path'] ?? null);
                $this->deletePath($slide['mobile_image_path'] ?? null);
            }
        }

        return $result;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private function deletePath(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function deleteImageIfAny(CmsHomepageBlock $block): void
    {
        $path = $block->content['image_path'] ?? null;

        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Merges new content over existing (for partial updates), then applies
     * per-type hardening: 'custom' blocks store admin-authored raw HTML,
     * run through HtmlSanitizerService before it is ever persisted — never
     * trusted verbatim just because only super_admin can write it.
     */
    private function sanitizeContent(string $type, array $incoming, array $existing = []): array
    {
        $merged = array_merge($existing, $incoming);

        if ($type === HomepageBlockTypes::CUSTOM && isset($merged['html'])) {
            $merged['html'] = $this->sanitizer->sanitize($merged['html']);
        }

        return $merged;
    }
}
