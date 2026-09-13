<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\StoreArticleRequest;
use App\Http\Requests\Cms\UpdateArticleRequest;
use App\Http\Resources\CmsArticleResource;
use App\Models\CmsArticle;
use App\Services\Cms\CmsArticleService;
use App\Support\SafeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Articles and news (Blueprint: "pages, articles, news, CMS content") —
 * public browsing only ever sees published rows; every write is
 * super_admin-only via Gate::manage-system-config, the same rule covering
 * the rest of CMS (see HomepageBlockController).
 */
class ArticleController extends Controller
{
    public function __construct(private readonly CmsArticleService $articles) {}

    /**
     * Public — published only, optionally filtered to just 'article' or
     * just 'news'. Reachable on a completely fresh, unmigrated deployment
     * (installer wizard page, bots, monitoring) before `cms_articles`
     * exists — SafeSchema treats that identically to "no DB connection at
     * all" and this degrades to an empty page instead of a raw
     * QueryException (see SafeSchema's docblock).
     */
    public function index(Request $request)
    {
        if (! SafeSchema::hasTable('cms_articles')) {
            return $this->ok([], meta: ['current_page' => 1, 'last_page' => 1, 'total' => 0]);
        }

        $query = CmsArticle::query()->where('status', 'published')->with('translations');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $articles = $query->latest('published_at')->paginate($request->integer('per_page', 15));

        return $this->ok(CmsArticleResource::collection($articles)->resolve(), meta: [
            'current_page' => $articles->currentPage(), 'last_page' => $articles->lastPage(), 'total' => $articles->total(),
        ]);
    }

    /** Same fresh-install guard as index() — a missing `cms_articles` table can never contain this slug, so this is a clean 404 rather than a QueryException. */
    public function show(string $slug)
    {
        if (! SafeSchema::hasTable('cms_articles')) {
            abort(404);
        }

        $article = CmsArticle::query()->where('slug', $slug)->where('status', 'published')
            ->with(['translations', 'author'])->firstOrFail();

        return $this->ok(new CmsArticleResource($article));
    }

    /** Admin listing — every status, for the dashboard's own article list. */
    public function adminIndex(Request $request)
    {
        Gate::authorize('manage-system-config');

        $query = CmsArticle::query()->with(['translations', 'author']);

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $articles = $query->latest()->paginate($request->integer('per_page', 15));

        return $this->ok(CmsArticleResource::collection($articles)->resolve(), meta: [
            'current_page' => $articles->currentPage(), 'last_page' => $articles->lastPage(), 'total' => $articles->total(),
        ]);
    }

    public function store(StoreArticleRequest $request)
    {
        Gate::authorize('manage-system-config');

        $article = $this->articles->create($request->validated(), $request->user()->id);

        return $this->created(new CmsArticleResource($article));
    }

    public function update(UpdateArticleRequest $request, CmsArticle $article)
    {
        Gate::authorize('manage-system-config');

        $article = $this->articles->update($article, $request->validated());

        return $this->ok(new CmsArticleResource($article));
    }

    public function destroy(CmsArticle $article)
    {
        Gate::authorize('manage-system-config');

        $this->articles->delete($article);

        return $this->ok(null, __('messages.cms.article_deleted'));
    }
}
