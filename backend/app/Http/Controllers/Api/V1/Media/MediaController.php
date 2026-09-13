<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;

/**
 * The generic upload/delete endpoint every dashboard image input can share
 * — used directly by CKEditor 5's own upload adapter (uploads standalone,
 * then just references the returned url()), and by any admin form that
 * uploads before its parent record is saved.
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaService $mediaService) {}

    /** Media Library — browse every uploaded file, optionally filtered to one collection. Never lists private collections like user_avatar. */
    public function index(Request $request)
    {
        $this->authorize('create', [Media::class, null]);

        $query = Media::query()->where('collection', '!=', 'user_avatar')->latest();

        if ($request->filled('collection')) {
            $query->where('collection', $request->string('collection')->toString());
        }

        $media = $query->paginate($request->integer('per_page', 30));

        return $this->ok(MediaResource::collection($media)->resolve(), meta: [
            'current_page' => $media->currentPage(), 'last_page' => $media->lastPage(), 'total' => $media->total(),
        ]);
    }

    public function store(StoreMediaRequest $request)
    {
        $collection = $request->string('collection')->toString();
        $this->authorize('create', [Media::class, $collection]);

        $media = $this->mediaService->store(
            $request->file('file'), $request->string('collection')->toString(), null, $request->user()
        );

        return $this->created(new MediaResource($media), __('messages.media.uploaded'));
    }

    public function destroy(Request $request, Media $media)
    {
        $this->authorize('delete', $media);

        $this->mediaService->delete($media);

        return $this->ok(null, __('messages.media.deleted'));
    }
}
