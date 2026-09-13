<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Services\Logging\ActivityLogger;
use App\Services\Sanitizer\HtmlSanitizerService;
use App\Support\SafeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catalog browsing is public (index/show, no auth required) and never
 * exposes fee data. "Agent product availability" is attached only when the
 * request is authenticated and the user's own referral-network agent is
 * known — never a quantity summed across agents (Blueprint: "Jangan
 * menjumlahkan keduanya").
 */
class ProductController extends Controller
{
    public function __construct(private readonly HtmlSanitizerService $sanitizer) {}

    /**
     * Catalog browsing is reachable on a completely fresh, unmigrated
     * deployment (installer wizard page, bots, monitoring) before
     * `products` exists — SafeSchema treats that identically to "no DB
     * connection at all" and this degrades to an empty page instead of a
     * raw QueryException (see SafeSchema's docblock).
     */
    public function index(Request $request)
    {
        if (! SafeSchema::hasTable('products')) {
            return $this->ok([], meta: ['current_page' => 1, 'last_page' => 1, 'total' => 0]);
        }

        $random = $request->boolean('random');

        $query = Product::query()
            ->where('status', 'active')
            // A variation-bearing product has no base_price of its own — this
            // subquery gives search/sort/filter a single "starting price" to
            // work with (its cheapest active variant), without ever assuming
            // one mode's price column means anything for the other mode.
            ->withMin(['variations' => fn ($q) => $q->where('is_active', true)], 'price')
            ->with(['category', 'images' => fn ($q) => $q->where('is_primary', true), 'variations' => fn ($q) => $q->where('is_active', true)])
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'category',
                fn ($c) => $c->where('slug', $request->string('category'))
            ))
            ->when($request->filled('search'), fn ($q) => $q->where(
                'name', 'like', '%'.$request->string('search')->toString().'%'
            ))
            ->when($request->filled('min_price'), fn ($q) => $q->havingRaw(
                'COALESCE(base_price, variations_min_price) >= ?', [$request->float('min_price')]
            ))
            ->when($request->filled('max_price'), fn ($q) => $q->havingRaw(
                'COALESCE(base_price, variations_min_price) <= ?', [$request->float('max_price')]
            ))
            // "Produk lainnya" on the detail page: exclude the product currently
            // being viewed so it never recommends itself.
            ->when($request->filled('exclude'), fn ($q) => $q->where('id', '!=', $request->integer('exclude')));

        if ($random) {
            // Random selection happens here, not in the frontend, so the
            // payload stays small and the full catalog is never shipped to
            // the client just to pick a handful of cards from it.
            $query->inRandomOrder();
        } else {
            match ($request->string('sort')->toString()) {
                'price_asc' => $query->orderByRaw('COALESCE(base_price, variations_min_price) ASC'),
                'price_desc' => $query->orderByRaw('COALESCE(base_price, variations_min_price) DESC'),
                default => $query->latest(),
            };
        }

        $products = $query->paginate($request->integer('per_page', 15));

        $this->attachAgentAvailability($request, collect($products->items()));

        return $this->ok(
            ProductResource::collection($products)->resolve(),
            meta: [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ]
        );
    }

    /** Same fresh-install guard as index() — a missing `products` table can never contain this slug, so this is a clean 404 rather than a QueryException. */
    public function show(Request $request, string $slug)
    {
        if (! SafeSchema::hasTable('products')) {
            abort(404);
        }

        $product = Product::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->with(['category', 'images', 'variations' => fn ($q) => $q->where('is_active', true)])
            ->firstOrFail();

        $this->attachAgentAvailability($request, collect([$product]));

        return $this->ok(new ProductResource($product));
    }

    public function store(StoreProductRequest $request)
    {
        $this->authorize('create', Product::class);

        $product = Product::create([
            ...$request->only('sku', 'category_id', 'has_variations', 'base_price', 'weight_grams', 'short_description'),
            'description' => $request->filled('description') ? $this->sanitizer->sanitize($request->string('description')->toString()) : null,
            'name' => $request->string('name'),
            'slug' => $request->filled('slug') ? $request->string('slug') : Str::slug($request->string('name')).'-'.uniqid(),
            'status' => $request->input('status', 'draft'),
            'created_by' => $request->user()->id,
        ]);

        ActivityLogger::log($request->user()->id, $product, 'product.created', null, [
            'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->created(new ProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $fields = ['sku', 'category_id', 'name', 'slug', 'description', 'short_description', 'base_price', 'weight_grams', 'status'];
        $before = $product->only($fields);
        $data = $request->only($fields);
        if (array_key_exists('description', $data) && $data['description'] !== null) {
            $data['description'] = $this->sanitizer->sanitize($data['description']);
        }
        $product->update($data);

        ActivityLogger::log($request->user()->id, $product, 'product.updated', null, [
            'actor_role' => $request->user()->role?->slug,
            'old' => $before, 'new' => $product->only($fields),
        ]);

        return $this->ok(new ProductResource($product));
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorize('delete', $product);

        $product->delete();

        ActivityLogger::log($request->user()->id, $product, 'product.deleted', null, [
            'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->ok(null, __('messages.product.deleted'));
    }

    /**
     * Mutates the given Product models in place, attaching a plain,
     * non-persisted `agent_available_quantity` attribute (and the same on
     * each loaded variation) resolved strictly from the viewer's own
     * agent_id — read via a single batched query per level, not N+1.
     */
    private function attachAgentAvailability(Request $request, Collection $products): void
    {
        $agentId = $request->user()?->agent_id;

        if (! $agentId) {
            return;
        }

        $simpleProductIds = $products->where('has_variations', false)->pluck('id');

        if ($simpleProductIds->isNotEmpty()) {
            $stockByProduct = ProductStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->whereIn('product_id', $simpleProductIds)
                ->get()
                ->keyBy('product_id');

            foreach ($products as $product) {
                if (! $product->has_variations) {
                    $product->agent_available_quantity = $stockByProduct->get($product->id)?->availableQuantity() ?? 0;
                }
            }
        }

        $variationIds = $products->flatMap(fn ($p) => $p->has_variations ? $p->variations->pluck('id') : collect());

        if ($variationIds->isNotEmpty()) {
            $stockByVariation = ProductVariationStock::withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->whereIn('product_variation_id', $variationIds)
                ->get()
                ->keyBy('product_variation_id');

            foreach ($products as $product) {
                if ($product->has_variations) {
                    foreach ($product->variations as $variation) {
                        $variation->agent_available_quantity = $stockByVariation->get($variation->id)?->availableQuantity() ?? 0;
                    }
                }
            }
        }
    }
}
