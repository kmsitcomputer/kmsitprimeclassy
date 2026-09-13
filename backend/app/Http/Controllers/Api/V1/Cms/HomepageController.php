<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use App\Models\CmsArticle;
use App\Models\CmsHomepageBlock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\HomepageBlockTypes;
use App\Support\SafeSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Public homepage rendering feed — one call returns every active block,
 * in order, with its references (categories/products/articles) already
 * resolved so the frontend never has to know how each block type is
 * structured internally to render it.
 */
class HomepageController extends Controller
{
    /**
     * Reachable on a completely fresh, unmigrated deployment (installer
     * wizard page, bots, monitoring) before `cms_homepage_blocks` exists —
     * SafeSchema treats that identically to "no DB connection at all" and
     * this degrades to an empty feed instead of a raw QueryException (see
     * SafeSchema's docblock).
     */
    public function index()
    {
        if (! SafeSchema::hasTable('cms_homepage_blocks')) {
            return $this->ok([]);
        }

        $blocks = CmsHomepageBlock::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')
            ->get();

        $rendered = $blocks->map(fn (CmsHomepageBlock $block) => [
            'id' => $block->id,
            'type' => $block->type,
            'content' => $this->resolveContent($block),
        ]);

        return $this->ok($rendered->all());
    }

    private function resolveContent(CmsHomepageBlock $block): array
    {
        $content = $block->content ?? [];

        if (isset($content['image_path'])) {
            $content['image_url'] = Storage::disk('public')->url($content['image_path']);
            unset($content['image_path']);
        }

        return match ($block->type) {
            HomepageBlockTypes::CATEGORY => [
                ...$content,
                'categories' => ProductCategory::query()
                    ->whereIn('id', $content['category_ids'] ?? [])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'slug'])
                    ->values(),
            ],
            HomepageBlockTypes::PRODUCT => [
                ...$content,
                'products' => $this->resolveProducts($content)->values(),
            ],
            HomepageBlockTypes::ARTICLE => [
                ...$content,
                'articles' => CmsArticle::query()
                    ->whereIn('id', $content['article_ids'] ?? [])
                    ->where('status', 'published')
                    ->with('translations')
                    ->get()
                    ->map(fn (CmsArticle $article) => [
                        'id' => $article->id,
                        'slug' => $article->slug,
                        'type' => $article->type,
                        'title' => $article->translations->first()?->title ?? $article->slug,
                        'cover_image_url' => $article->cover_image_path
                            ? Storage::disk('public')->url($article->cover_image_path)
                            : null,
                        'published_at' => $article->published_at,
                    ])
                    ->values(),
            ],
            default => $content,
        };
    }

    private function resolveProducts(array $content): Collection
    {
        $query = Product::query()->where('status', 'active')->with(['images' => fn ($q) => $q->where('is_primary', true)]);

        if (! empty($content['product_ids'])) {
            $query->whereIn('id', $content['product_ids']);
        } elseif (! empty($content['category_id'])) {
            $query->where('category_id', $content['category_id']);
        }

        return $query->limit($content['limit'] ?? 8)->get()->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'has_variations' => $product->has_variations,
            'base_price' => $product->base_price,
            'image_url' => $product->images->first()
                ? Storage::disk('public')->url($product->images->first()->path)
                : null,
        ]);
    }
}
