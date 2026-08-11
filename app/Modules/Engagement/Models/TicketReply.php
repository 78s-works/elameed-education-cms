<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A reply within a support ticket thread (M09, B24 / VD Item 11) — authored by
 * the student or by staff. Attachments reuse the polymorphic {@see Attachment}.
 */
class TicketReply extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
