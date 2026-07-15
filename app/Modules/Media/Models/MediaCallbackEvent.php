<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Replay/idempotency ledger for signed processing callbacks. Deliberately NOT
 * tenant-scoped: it is consulted at ingest, before a tenant is resolved, purely
 * to dedupe by the host's unique `event_id` (docs/MEDIA_HOST_API_v1.md §6).
 */
class MediaCallbackEvent extends Model
{
    protected $fillable = [
        'event_id', 'tenant_id', 'media_version_id', 'type', 'payload_hash', 'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
