<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification engine — per-user opt-out (doc 10 §4). Absence of a row = allowed;
 * `is_enabled = false` skips that recipient for one (type, channel). DB-only.
 *
 * @property bool $is_enabled
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type_id',
        'channel',
        'is_enabled',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'is_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
