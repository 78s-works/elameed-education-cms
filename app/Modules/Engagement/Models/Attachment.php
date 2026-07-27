<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A polymorphic user attachment — image, voice note, or file (M09, FR-M09-05).
 * Uploaded standalone, then linked to a comment (or future forum post / message).
 */
class Attachment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const KIND_IMAGE = 'image';

    public const KIND_AUDIO = 'audio';

    public const KIND_FILE = 'file';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'kind',
        'storage_key',
        'mime',
        'size_bytes',
        'duration_sec',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_sec' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Public URL for the stored file (dev: public disk; prod: object storage). */
    public function url(): string
    {
        return Storage::disk($this->disk())->url($this->storage_key);
    }

    public function disk(): string
    {
        return (string) config('media.attachments_disk', 'public');
    }
}
