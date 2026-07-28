<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification engine — per-notification delivery log (doc 10 §3). Child of a
 * delivered message row; records `queued`/`sent`/`failed` + metadata.
 */
class NotificationLog extends Model
{
    protected $fillable = [
        'notification_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'notification_id');
    }
}
