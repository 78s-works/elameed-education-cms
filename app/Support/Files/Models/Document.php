<?php

namespace App\Support\Files\Models;

use App\Models\User;
use App\Support\Files\Enums\DocumentKind;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Enums\DocumentStatus;
use App\Support\Files\Enums\DocumentVisibility;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One stored file. Every image, video, PDF, receipt and submission in the system
 * is a row here — see the migration for why, and docs/design/unified-documents.md
 * for the two linking patterns.
 *
 * The model deliberately exposes no way to write a file: creating a Document
 * without going through DocumentService would put bytes on disk under an
 * unmanaged path, which is the habit this table exists to break.
 *
 * @property DocumentPurpose $purpose
 * @property DocumentKind $kind
 * @property DocumentVisibility $visibility
 * @property DocumentStatus $status
 */
class Document extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'purpose',
        'kind',
        'visibility',
        'status',
        'disk',
        'storage_key',
        'original_name',
        'mime',
        'extension',
        'size_bytes',
        'checksum',
        'documentable_type',
        'documentable_id',
        'meta',
    ];

    protected $casts = [
        'purpose' => DocumentPurpose::class,
        'kind' => DocumentKind::class,
        'visibility' => DocumentVisibility::class,
        'status' => DocumentStatus::class,
        'size_bytes' => 'integer',
        'meta' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // — relations —

    /** Pattern B owner: Comment, TicketReply, ExamAttempt, Lesson, Tenant. */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // — state —

    /**
     * Is this document claimed by anything? A Pattern B row answers from its own
     * `documentable_*`; a Pattern A row is referenced from elsewhere, so the
     * answer comes from DocumentLinkResolver, not from here.
     */
    public function isAttached(): bool
    {
        return $this->documentable_type !== null && $this->documentable_id !== null;
    }

    public function isPublic(): bool
    {
        return $this->visibility === DocumentVisibility::Public;
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->storage_key);
    }

    /**
     * Direct URL. Only meaningful for public documents — a private one is served
     * through the policy-checked download route or a signed URL, never like this.
     */
    public function publicUrl(): ?string
    {
        return $this->isPublic()
            ? Storage::disk($this->disk)->url($this->storage_key)
            : null;
    }

    // — scopes —

    public function scopeOfPurpose(Builder $query, DocumentPurpose|array $purpose): Builder
    {
        return is_array($purpose)
            ? $query->whereIn('purpose', array_map(fn (DocumentPurpose $p) => $p->value, $purpose))
            : $query->where('purpose', $purpose->value);
    }

    public function scopeOfKind(Builder $query, DocumentKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    /** Pattern B rows still waiting to be linked to their owner. */
    public function scopeUnattached(Builder $query): Builder
    {
        return $query->whereNull('documentable_type')->whereNull('documentable_id');
    }

    public function scopeAttachedTo(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey());
    }
}
