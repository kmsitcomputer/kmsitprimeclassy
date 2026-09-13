<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\StoreHomepageBlockRequest;
use App\Http\Requests\Cms\UpdateHomepageBlockRequest;
use App\Http\Resources\CmsHomepageBlockResource;
use App\Models\CmsHomepageBlock;
use App\Services\Cms\HomepageBlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Super Admin-only management of homepage blocks (Gate::manage-system-config,
 * see AppServiceProvider — the same rule covering settings/payment/shipping/
 * CMS generally). Public rendering lives in HomepageController.
 */
class HomepageBlockController extends Controller
{
    public function __construct(private readonly HomepageBlockService $blocks) {}

    public function index()
    {
        Gate::authorize('manage-system-config');

        $blocks = CmsHomepageBlock::query()->orderBy('sort_order')->get();

        return $this->ok(CmsHomepageBlockResource::collection($blocks)->resolve());
    }

    public function store(StoreHomepageBlockRequest $request)
    {
        Gate::authorize('manage-system-config');

        $block = $this->blocks->create(
            $request->string('type')->toString(),
            $request->input('content', []),
            $request->metaData(),
            $request->file('image'),
        );

        Log::info('cms.homepage_block.created', ['block_id' => $block->id, 'type' => $block->type, 'by' => $request->user()->id]);

        return $this->created(new CmsHomepageBlockResource($block));
    }

    public function update(UpdateHomepageBlockRequest $request, CmsHomepageBlock $block)
    {
        Gate::authorize('manage-system-config');

        $block = $this->blocks->update(
            $block,
            $request->input('content', []),
            $request->metaData(),
            $request->file('image'),
            $request->boolean('remove_image'),
        );

        Log::info('cms.homepage_block.updated', ['block_id' => $block->id, 'by' => $request->user()->id]);

        return $this->ok(new CmsHomepageBlockResource($block));
    }

    public function destroy(Request $request, CmsHomepageBlock $block)
    {
        Gate::authorize('manage-system-config');

        $blockId = $block->id;
        $this->blocks->delete($block);

        Log::info('cms.homepage_block.deleted', ['block_id' => $blockId, 'by' => $request->user()->id]);

        return $this->ok(null, __('messages.cms.block_deleted'));
    }

    public function toggle(Request $request, CmsHomepageBlock $block)
    {
        Gate::authorize('manage-system-config');

        $block = $this->blocks->toggle($block);

        return $this->ok(new CmsHomepageBlockResource($block), $block->is_active ? __('messages.cms.block_activated') : __('messages.cms.block_deactivated'));
    }

    public function reorder(Request $request)
    {
        Gate::authorize('manage-system-config');

        $validated = $request->validate([
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => [Rule::exists('cms_homepage_blocks', 'id')],
        ]);

        $this->blocks->reorder($validated['block_ids']);

        $blocks = CmsHomepageBlock::query()->orderBy('sort_order')->get();

        return $this->ok(CmsHomepageBlockResource::collection($blocks)->resolve(), __('messages.cms.reorder_saved'));
    }
}
