<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Rules\SafeLinkUrl;

/**
 * The registry of homepage block kinds. Adding a new kind of block is a
 * one-entry edit here (plus a matching frontend renderer) — never a
 * migration, never a hard-coded homepage template. `cms_homepage_blocks.type`
 * is a plain string column validated against the keys of this array.
 *
 * Each entry documents:
 *   - `image`: whether this block accepts an uploaded image (see
 *     HomepageBlockService — the file itself is never a URL the admin types in).
 *   - `content_rules`: extra Laravel validation rules for `content.*` fields,
 *     applied on top of the base rules in StoreHomepageBlockRequest.
 */
class HomepageBlockTypes
{
    public const HERO = 'hero';

    public const BANNER = 'banner';

    public const CATEGORY = 'category';

    public const PRODUCT = 'product';

    public const PROMOTIONAL = 'promotional';

    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const ARTICLE = 'article';

    public const CTA = 'cta';

    public const CUSTOM = 'custom';

    public const SOCIAL_MEDIA = 'social_media';

    public const WHATSAPP_CHAT = 'whatsapp_chat';

    public const MARKETPLACE_LINKS = 'marketplace_links';

    public const BANNER_SLIDER = 'banner_slider';

    public const MAX_SLIDES = 10;

    public static function definitions(): array
    {
        return [
            self::HERO => [
                'image' => true,
                'content_rules' => [
                    'content.heading' => ['required', 'string', 'max:150'],
                    'content.subheading' => ['nullable', 'string', 'max:255'],
                    'content.cta_label' => ['nullable', 'string', 'max:50'],
                    'content.cta_url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
                    // Presentation (all optional: a block saved before these existed renders as before).
                    'content.image_fit' => ['nullable', 'in:cover,contain'],
                    'content.image_position' => ['nullable', 'in:center,top,bottom,left,right'],
                    'content.overlay' => ['nullable', 'in:none,light,dark'],
                    'content.align' => ['nullable', 'in:left,center'],
                ],
            ],
            self::BANNER => [
                'image' => true,
                'content_rules' => [
                    'content.url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
                ],
            ],
            self::CATEGORY => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.category_ids' => ['required', 'array', 'min:1'],
                    'content.category_ids.*' => ['integer', 'exists:product_categories,id'],
                ],
            ],
            self::PRODUCT => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
                    'content.product_ids' => ['nullable', 'array'],
                    'content.product_ids.*' => ['integer', 'exists:products,id'],
                    'content.limit' => ['nullable', 'integer', 'min:1', 'max:24'],
                ],
            ],
            self::PROMOTIONAL => [
                'image' => true,
                'content_rules' => [
                    'content.heading' => ['required', 'string', 'max:150'],
                    'content.description' => ['nullable', 'string', 'max:500'],
                    'content.url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
                ],
            ],
            self::TEXT => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.body' => ['required', 'string', 'max:5000'],
                ],
            ],
            self::IMAGE => [
                'image' => true,
                'content_rules' => [
                    'content.alt' => ['nullable', 'string', 'max:150'],
                    'content.url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
                ],
            ],
            self::ARTICLE => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.article_ids' => ['required', 'array', 'min:1'],
                    'content.article_ids.*' => ['integer', 'exists:cms_articles,id'],
                ],
            ],
            self::CTA => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['required', 'string', 'max:150'],
                    'content.button_label' => ['required', 'string', 'max:50'],
                    'content.button_url' => ['required', 'string', 'max:255', new SafeLinkUrl],
                    'content.description' => ['nullable', 'string', 'max:300'],
                    'content.theme' => ['nullable', 'in:brand,dark,light,sand'],
                    'content.align' => ['nullable', 'in:left,center'],
                ],
            ],
            self::CUSTOM => [
                'image' => false,
                'content_rules' => [
                    // Sanitized server-side before storage — see HomepageBlockService.
                    'content.html' => ['required', 'string', 'max:20000'],
                ],
            ],
            self::SOCIAL_MEDIA => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.description' => ['nullable', 'string', 'max:500'],
                    'content.layout' => ['required', 'in:cards,compact'],
                    'content.platforms' => ['required', 'array'],
                    'content.platforms.instagram.enabled' => ['required', 'boolean'],
                    'content.platforms.instagram.url' => ['nullable', 'url:https', 'regex:/^https:\/\/(?:[a-z0-9-]+\.)*instagram\.com(?:[\/?#]|$)/i', 'max:500'],
                    'content.platforms.tiktok.enabled' => ['required', 'boolean'],
                    'content.platforms.tiktok.url' => ['nullable', 'url:https', 'regex:/^https:\/\/(?:[a-z0-9-]+\.)*tiktok\.com(?:[\/?#]|$)/i', 'max:500'],
                    'content.platforms.facebook.enabled' => ['required', 'boolean'],
                    'content.platforms.facebook.url' => ['nullable', 'url:https', 'regex:/^https:\/\/(?:[a-z0-9-]+\.)*facebook\.com(?:[\/?#]|$)/i', 'max:500'],
                ],
            ],
            self::WHATSAPP_CHAT => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.description' => ['nullable', 'string', 'max:500'],
                    'content.phone' => ['required', 'regex:/^[1-9][0-9]{7,14}$/'],
                    'content.message' => ['nullable', 'string', 'max:1000'],
                    'content.button_label' => ['required', 'string', 'max:50'],
                    'content.mode' => ['required', 'in:card,floating'],
                ],
            ],
            self::MARKETPLACE_LINKS => [
                'image' => false,
                'content_rules' => [
                    'content.heading' => ['nullable', 'string', 'max:150'],
                    'content.description' => ['nullable', 'string', 'max:500'],
                    'content.layout' => ['required', 'in:cards,horizontal'],
                    'content.platforms' => ['required', 'array'],
                    'content.platforms.tokopedia.enabled' => ['required', 'boolean'],
                    'content.platforms.tokopedia.url' => ['nullable', 'url:https', 'regex:/^https:\/\/(?:[a-z0-9-]+\.)*tokopedia\.com(?:[\/?#]|$)/i', 'max:500'],
                    'content.platforms.shopee.enabled' => ['required', 'boolean'],
                    'content.platforms.shopee.url' => ['nullable', 'url:https', 'regex:/^https:\/\/(?:[a-z0-9-]+\.)*shopee\.co\.id(?:[\/?#]|$)/i', 'max:500'],
                ],
            ],
            self::BANNER_SLIDER => [
                // Slides carry their own uploaded images (slide_images[<key>][desktop|mobile]); the single
                // block-level `image` is not used.
                'image' => false,
                'content_rules' => [
                    'content.layout' => ['required', 'in:contained,wide'],
                    'content.autoplay' => ['required', 'boolean'],
                    'content.interval_seconds' => ['required', 'integer', 'min:3', 'max:10'],
                    'content.show_arrows' => ['required', 'boolean'],
                    'content.show_dots' => ['required', 'boolean'],
                    'content.slides' => ['required', 'array', 'min:1', 'max:'.self::MAX_SLIDES],
                    // `key` is the slide's stable identity: it links an upload and the stored image to the slide.
                    'content.slides.*.key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,40}$/', 'distinct'],
                    'content.slides.*.title' => ['nullable', 'string', 'max:150'],
                    'content.slides.*.description' => ['nullable', 'string', 'max:300'],
                    'content.slides.*.alt' => ['nullable', 'string', 'max:150'],
                    'content.slides.*.button_label' => ['nullable', 'string', 'max:50'],
                    'content.slides.*.button_url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
                    'content.slides.*.overlay' => ['nullable', 'in:none,light,dark'],
                    'content.slides.*.content_position' => ['nullable', 'in:left,center,right'],
                    'content.slides.*.remove_mobile' => ['nullable', 'boolean'],
                ],
            ],
        ];
    }

    /** Request-level file rules for block types whose images live inside their content (not the single `image`). */
    public static function fileRules(?string $type): array
    {
        if ($type !== self::BANNER_SLIDER) {
            return [];
        }

        return [
            'slide_images' => ['nullable', 'array', 'max:'.self::MAX_SLIDES],
            'slide_images.*' => ['array'],
            'slide_images.*.desktop' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'slide_images.*.mobile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * Normalises multipart form values before validation: platform `enabled`
     * flags sent as "true"/"false" become real booleans, and category ids sent
     * as strings become unique integers in the admin's order. Anything else is
     * left untouched so invalid input is still rejected.
     */
    public static function normalizeContent(?string $type, mixed $content): mixed
    {
        if ($type === self::CATEGORY && is_array($content) && is_array($content['category_ids'] ?? null)) {
            // Multipart form values arrive as strings. Keep the admin's order, store
            // integers once each; non-numeric values are left for validation to reject.
            $ids = array_map(fn ($id) => is_string($id) && ctype_digit($id) ? (int) $id : $id, $content['category_ids']);
            $content['category_ids'] = array_values(array_unique($ids, SORT_REGULAR));

            return $content;
        }

        if (! in_array($type, [self::SOCIAL_MEDIA, self::MARKETPLACE_LINKS], true)
            || ! is_array($content) || ! is_array($content['platforms'] ?? null)) {
            return $content;
        }

        foreach ($content['platforms'] as $platform => $config) {
            if (is_array($config) && is_string($config['enabled'] ?? null)) {
                $flag = filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($flag !== null) {
                    $content['platforms'][$platform]['enabled'] = $flag;
                }
            }
        }

        return $content;
    }

    /**
     * Slide list for output: storage paths become public URLs. The public feed drops the paths entirely;
     * the admin view keeps them next to the URLs (as it already does for a block's `image_path`).
     *
     * @param  array<int, array<string, mixed>>  $slides
     * @return array<int, array<string, mixed>>
     */
    public static function resolveSlides(array $slides, bool $keepPaths): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        return array_values(array_map(function ($slide) use ($disk, $keepPaths) {
            $slide = is_array($slide) ? $slide : [];
            $slide['image_url'] = ! empty($slide['image_path']) ? $disk->url($slide['image_path']) : null;
            $slide['mobile_image_url'] = ! empty($slide['mobile_image_path']) ? $disk->url($slide['mobile_image_path']) : null;
            if (! $keepPaths) {
                unset($slide['image_path'], $slide['mobile_image_path']);
            }
            unset($slide['remove_mobile']);

            return $slide;
        }, $slides));
    }

    public static function slugs(): array
    {
        return array_keys(self::definitions());
    }

    public static function acceptsImage(string $type): bool
    {
        return self::definitions()[$type]['image'] ?? false;
    }

    public static function contentRules(string $type): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$type])) {
            throw new ApiException("Tipe block \"{$type}\" tidak dikenal.", 422, ['type' => 'Tidak valid.']);
        }

        return $definitions[$type]['content_rules'];
    }
}
