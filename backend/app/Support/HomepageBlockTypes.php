<?php

namespace App\Support;

use App\Exceptions\ApiException;

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

    public static function definitions(): array
    {
        return [
            self::HERO => [
                'image' => true,
                'content_rules' => [
                    'content.heading' => ['required', 'string', 'max:150'],
                    'content.subheading' => ['nullable', 'string', 'max:255'],
                    'content.cta_label' => ['nullable', 'string', 'max:50'],
                    'content.cta_url' => ['nullable', 'string', 'max:255'],
                ],
            ],
            self::BANNER => [
                'image' => true,
                'content_rules' => [
                    'content.url' => ['nullable', 'string', 'max:255'],
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
                    'content.url' => ['nullable', 'string', 'max:255'],
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
                    'content.url' => ['nullable', 'string', 'max:255'],
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
                    'content.button_url' => ['required', 'string', 'max:255'],
                ],
            ],
            self::CUSTOM => [
                'image' => false,
                'content_rules' => [
                    // Sanitized server-side before storage — see HomepageBlockService.
                    'content.html' => ['required', 'string', 'max:20000'],
                ],
            ],
        ];
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
