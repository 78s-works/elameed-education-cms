<?php

namespace App\Modules\Media\Models;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Support\Files\Models\Document;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A video / PDF / file / link. Tenant-scoped. Attachments carry `lesson_id`;
 * videos are referenced by lessons.video_asset_id (populated in the Media step).
 *
 * @property MediaType $type
 * @property MediaStatus $status
 */
class MediaAsset extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'lesson_id',
        'type',
        'status',
        'provider',
        'current_version_id',
        'thumbnail_document_id',
        'title',
        'source_document_id',
        'hls_path',
        'encryption_key_ref',
        'renditions',
        'duration_sec',
        'url',
        'watermark_policy',
        'downloadable',
        'access_scope',
        'sort_order',
    ];

    protected $casts = [
        'type' => MediaType::class,
        'status' => MediaStatus::class,
        'renditions' => 'array',
        'downloadable' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** All Media Host versions of this asset (remote provider). */
    public function versions(): HasMany
    {
        return $this->hasMany(MediaVersion::class, 'media_asset_id');
    }

    /** The current servable version (set only when a version reaches `ready`). */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MediaVersion::class, 'current_version_id');
    }

    public function isRemote(): bool
    {
        return $this->provider === 'remote';
    }

    // — the two files behind a video —
    //
    // A video is not one file: the HLS pipeline owns the encrypted renditions,
    // the keys and the versions, and none of that belongs in the documents
    // ledger. What does belong there is the raw upload and the poster, so they
    // show up in the academy's library and count toward storage.
    //
    // `source_key` and `thumbnail_url` survive as accessors on purpose: the
    // transcoder, the thumbnailer and the playback service read them, and this
    // change deliberately does not touch video playback code.

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function thumbnailDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'thumbnail_document_id');
    }

    public function getSourceKeyAttribute(): ?string
    {
        return $this->sourceDocument?->storage_key;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnailDocument?->publicUrl();
    }
}
