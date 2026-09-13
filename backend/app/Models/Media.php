<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** A single uploaded file's metadata — see MediaService for the only place these rows get created. */
class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'disk', 'path', 'collection', 'original_filename', 'mime_type', 'extension',
        'size', 'width', 'height', 'mediable_type', 'mediable_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Never hand out a raw path — always the disk's own public URL (swapping to S3 later changes nothing here). */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
