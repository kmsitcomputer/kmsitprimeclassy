<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\StorePageRequest;
use App\Http\Requests\Cms\UpdatePageRequest;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use App\Services\Cms\CmsPageService;
use App\Support\SafeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Static pages (About, Terms, ...) — same public/admin split and write-gate as ArticleController. */
class PageController extends Controller
{
    public function __construct(private readonly CmsPageService $pages) {}

    /**
     * Reachable on a completely fresh, unmigrated deployment (installer
     * wizard page, bots, monitoring) before `cms_pages` exists — a missing
     * table can never contain this slug, so SafeSchema turns that into a
     * clean 404 instead of a raw QueryException (see SafeSchema's docblock).
     */
    public function show(string $slug)
    {
        if (! SafeSchema::hasTable('cms_pages')) {
            abort(404);
        }

        $page = CmsPage::query()->where('slug', $slug)->where('status', 'published')
            ->with('translations')->firstOrFail();

        return $this->ok(new CmsPageResource($page));
    }

    public function adminIndex(Request $request)
    {
        Gate::authorize('manage-system-config');

        $query = CmsPage::query()->with('translations');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $pages = $query->latest()->paginate($request->integer('per_page', 15));

        return $this->ok(CmsPageResource::collection($pages)->resolve(), meta: [
            'current_page' => $pages->currentPage(), 'last_page' => $pages->lastPage(), 'total' => $pages->total(),
        ]);
    }

    public function store(StorePageRequest $request)
    {
        Gate::authorize('manage-system-config');

        $page = $this->pages->create($request->validated());

        return $this->created(new CmsPageResource($page));
    }

    public function update(UpdatePageRequest $request, CmsPage $page)
    {
        Gate::authorize('manage-system-config');

        $page = $this->pages->update($page, $request->validated());

        return $this->ok(new CmsPageResource($page));
    }

    public function destroy(CmsPage $page)
    {
        Gate::authorize('manage-system-config');

        $this->pages->delete($page);

        return $this->ok(null, __('messages.cms.page_deleted'));
    }
}
