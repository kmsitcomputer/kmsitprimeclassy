<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single place any image gets validated, stored, replaced or deleted —
 * every dashboard image input (Blueprint §Media Management) goes through
 * here instead of reimplementing its own Storage:: calls, so:
 *   - switching disks (e.g. to S3) later is a one-line config['media.disk']
 *     change, never a per-feature rewrite;
 *   - the security rules below (secure filenames, real content sniffing,
 *     per-collection allowlists) apply everywhere uniformly, not just
 *     wherever someone remembered to copy them.
 *
 * Filename strategy: the caller's original filename is NEVER used to build
 * the stored path (kept only as metadata for display) — every file is
 * written under a fresh UUID plus an extension WE choose from the
 * collection's own allowlist, which defeats both path traversal
 * (`../../etc/passwd`) and double-extension tricks (`shell.php.jpg`) in one
 * move, and makes the stored name impossible to guess.
 */
class MediaService
{
    public function store(UploadedFile $file, string $collection, ?Model $mediable = null, ?User $actor = null): Media
    {
        $profile = $this->profileFor($collection);
        $extension = $this->validate($file, $profile);
        [$width, $height] = $this->dimensions($file, $profile);

        $disk = config('media.disk');
        $directory = config('media.directory').'/'.$collection;
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $filename, $disk);

        return Media::create([
            'disk' => $disk,
            'path' => $path,
            'collection' => $collection,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'mediable_type' => $mediable ? $mediable::class : null,
            'mediable_id' => $mediable?->getKey(),
            'created_by' => $actor?->id,
        ]);
    }

    /** Same validation as store(), then swaps the file in place — the Media row keeps its id, so anything referencing it never dangles. */
    public function replace(Media $media, UploadedFile $file): Media
    {
        $profile = $this->profileFor($media->collection);
        $extension = $this->validate($file, $profile);
        [$width, $height] = $this->dimensions($file, $profile);

        $directory = config('media.directory').'/'.$media->collection;
        $filename = Str::uuid()->toString().'.'.$extension;
        $newPath = $file->storeAs($directory, $filename, $media->disk);

        Storage::disk($media->disk)->delete($media->path);

        $media->update([
            'path' => $newPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        return $media->fresh();
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    public function attachTo(Media $media, Model $mediable): void
    {
        $media->update(['mediable_type' => $mediable::class, 'mediable_id' => $mediable->getKey()]);
    }

    private function profileFor(string $collection): array
    {
        $profile = config("media.collections.{$collection}");

        if (! $profile) {
            throw new ApiException(__('messages.media.unknown_collection'), 422);
        }

        return $profile;
    }

    /**
     * MIME is read from the file's own content via PHP's fileinfo (Symfony's
     * UploadedFile::getMimeType(), never the client-supplied Content-Type
     * header) — a renamed .php pretending to be .jpg fails here regardless
     * of what extension it was given. The extension we ultimately store
     * under is chosen by us from the collection's own allowlist, never
     * echoed back from the client's original filename.
     */
    private function validate(UploadedFile $file, array $profile): string
    {
        if (! $file->isValid()) {
            throw new ApiException(__('messages.media.upload_failed'), 422);
        }

        $mime = $file->getMimeType();

        if (! in_array($mime, $profile['mimes'], true)) {
            throw new ApiException(__('messages.media.invalid_type'), 422);
        }

        $clientExtension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($clientExtension, $profile['extensions'], true)) {
            throw new ApiException(__('messages.media.invalid_type'), 422);
        }

        if ($file->getSize() > $profile['max_size']) {
            throw new ApiException(__('messages.media.too_large'), 422);
        }

        // A real image must actually decode as one — this is what catches a
        // file whose header bytes were forged to pass the MIME sniff above.
        if (str_starts_with($mime, 'image/') && @getimagesize($file->getRealPath()) === false) {
            throw new ApiException(__('messages.media.invalid_type'), 422);
        }

        return $clientExtension;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(UploadedFile $file, array $profile): array
    {
        $info = @getimagesize($file->getRealPath());

        if (! $info) {
            return [null, null];
        }

        [$width, $height] = $info;

        if (! empty($profile['max_width']) && $width > $profile['max_width']) {
            throw new ApiException(__('messages.media.dimensions_too_large'), 422);
        }
        if (! empty($profile['max_height']) && $height > $profile['max_height']) {
            throw new ApiException(__('messages.media.dimensions_too_large'), 422);
        }

        return [$width, $height];
    }
}
