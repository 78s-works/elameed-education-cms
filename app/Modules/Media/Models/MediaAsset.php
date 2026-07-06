<?php

namespace App\Modules\Media\Models;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'title',
        'source_key',
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
}
