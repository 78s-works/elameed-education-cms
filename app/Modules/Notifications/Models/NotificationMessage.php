<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notification engine — delivered message (doc 10 §3, §7). The in-app inbox row
 * / sms record produced on a successful send. Table `new_notifications` (the
 * legacy simple `notifications` table is untouched — see the migration).
 *
 * Named `NotificationMessage` (not `Notification`) to avoid colliding with the
 * legacy `App\Modules\Notifications\Models\Notification`. Tenant-owned →
 * BelongsToTenant.
 *
 * @property bool $is_read
 * @property NotificationChannel $channel
 */
class NotificationMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'new_notifications';

    protected $fillable = [
        'tenant_id',
        'notification_event_id',
        'user_id',
        'channel',
        'title',
        'body',
        'is_read',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'is_read' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
