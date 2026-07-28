<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notification engine — immutable dispatch audit (doc 10 §3, §11). Records one
 * event per dispatch that passes the lifecycle gate. `payload` is the curated
 * audit payload only — never the render variables. Not BelongsToTenant: read
 * cross-tenant by central admin; the engine scopes in-query.
 *
 * @property int|null $tenant_id
 * @property int|null $triggered_by
 */
class NotificationEvent extends Model
{
    protected $fillable = [
        'notification_type_id',
        'tenant_id',
        'entity_type',
        'entity_id',
        'payload',
        'triggered_by',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationMessage::class, 'notification_event_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(NotificationFailure::class, 'notification_event_id');
    }
}
