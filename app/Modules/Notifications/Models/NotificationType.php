<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationModule;
use App\Modules\Notifications\Enums\NotificationSeverity;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notification engine — type catalog entry (doc 10 §3). GLOBAL (not
 * tenant-scoped); `key` is the identity. Authored by central admin; only
 * `status = ready` types are dispatchable and tenant-visible (§8).
 *
 * @property string $key
 * @property NotificationTypeStatus $status
 */
class NotificationType extends Model
{
    protected $fillable = [
        'key',
        'module',
        'severity',
        'is_system',
        'status',
    ];

    protected $casts = [
        'module' => NotificationModule::class,
        'severity' => NotificationSeverity::class,
        'status' => NotificationTypeStatus::class,
        'is_system' => 'boolean',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationEvent::class);
    }
}
