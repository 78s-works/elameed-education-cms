<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\TemplateScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notification engine — template binding (doc 10 §3, §6). Routes a
 * `(type, channel)` to a scope + activation; holds NO text (that is in
 * translations). System rows have `tenant_id = NULL`; tenant overrides carry the
 * teacher's id.
 *
 * Deliberately NOT BelongsToTenant: the engine reads system (NULL) + tenant rows
 * together and scopes them explicitly (§6). A tenant global scope would hide the
 * system rows.
 *
 * @property TemplateScope $scope
 * @property NotificationChannel $channel
 * @property bool $is_active
 * @property int|null $tenant_id
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'notification_type_id',
        'scope',
        'tenant_id',
        'channel',
        'is_active',
        'created_by',
        'edited_by',
    ];

    protected $casts = [
        'scope' => TemplateScope::class,
        'channel' => NotificationChannel::class,
        'is_active' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(NotificationTemplateTranslation::class);
    }

    public function isSystem(): bool
    {
        return $this->scope === TemplateScope::System;
    }
}
