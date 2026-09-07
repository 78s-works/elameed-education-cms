<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Enums\BroadcastStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A custom (human-written) notification — see the `notification_broadcasts`
 * migration. Deliberately NOT `BelongsToTenant`: a platform-admin broadcast to
 * every teacher carries `tenant_id = null`, which a forced tenant scope would
 * hide. Callers scope explicitly (`forTenant`).
 *
 * @property BroadcastAudience $audience_type
 * @property BroadcastStatus $status
 * @property array<int, int|string>|null $audience_ids
 * @property array<int, string> $channels
 */
class NotificationBroadcast extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'created_by',
        'audience_type',
        'audience_ids',
        'channels',
        'title_ar',
        'body_ar',
        'title_en',
        'body_en',
        'status',
        'scheduled_at',
        'sent_at',
        'recipient_count',
        'sms_recipient_count',
        'sms_segments',
        'sms_cost_minor',
        'currency',
        'stats',
        'failure_reason',
    ];

    protected $casts = [
        'audience_type' => BroadcastAudience::class,
        'status' => BroadcastStatus::class,
        'audience_ids' => 'array',
        'channels' => 'array',
        'stats' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** `uuid` is a plain unique column; the primary key stays the auto-increment id. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $broadcast): void {
            if (empty($broadcast->uuid)) {
                $broadcast->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Academy rows only — the platform-admin surface passes null. */
    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    /** Due to go out: scheduled and the clock has passed (null = send now). */
    public function scopeDue($query)
    {
        return $query
            ->where('status', BroadcastStatus::Scheduled->value)
            ->where(function ($q): void {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            });
    }
}
