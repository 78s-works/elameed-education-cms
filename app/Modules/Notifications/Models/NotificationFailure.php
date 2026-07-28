<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification engine — delivery failure (doc 10 §3). One row per failed
 * recipient×channel attempt, linked to the event for the central admin auditor.
 */
class NotificationFailure extends Model
{
    protected $fillable = [
        'notification_event_id',
        'user_id',
        'channel',
        'error_message',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
