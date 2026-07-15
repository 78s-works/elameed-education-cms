<?php

namespace App\Modules\Media\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authorized, resumable upload intent to the Media Host. The unique
 * `idempotency_key` means a retried "start upload" returns the SAME intent
 * instead of creating a duplicate on the host (docs/MEDIA_HOST_API_v1.md §6).
 */
class MediaUploadSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'media_version_id', 'created_by', 'idempotency_key', 'host_upload_id',
        'upload_url', 'protocol', 'size_bytes', 'max_bytes', 'content_type',
        'checksum_sha256', 'state', 'expires_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'max_bytes' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(MediaVersion::class, 'media_version_id');
    }
}
