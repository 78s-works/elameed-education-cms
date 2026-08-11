<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Engagement\Enums\TicketPriority;
use App\Modules\Engagement\Enums\TicketStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A student's support ticket to teacher/assistant (M09, B24 / VD Item 11):
 * subject + opening message + optional attachments, with a normal|urgent
 * priority and an open|in_progress|closed lifecycle. Staff replies live in
 * {@see TicketReply}; attachments reuse the polymorphic {@see Attachment}.
 *
 * @property TicketStatus $status
 * @property TicketPriority $priority
 */
class SupportTicket extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'assigned_to',
        'subject',
        'body',
        'priority',
        'status',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'open',
    ];

    protected $casts = [
        'status' => TicketStatus::class,
        'priority' => TicketPriority::class,
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The student who opened the ticket. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Staff member the ticket is assigned to (nullable until triaged). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'ticket_id');
    }

    /** Polymorphic attachments on the opening message (reuses M09 Attachment). */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
