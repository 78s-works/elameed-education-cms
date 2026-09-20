<?php

namespace App\Modules\Reporting\Models;

use App\Models\User;
use App\Modules\Reporting\Enums\ExportFormat;
use App\Modules\Reporting\Enums\ExportStatus;
use App\Modules\Reporting\Enums\ReportType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested report file (EDU-021): the request record, its progress, and the
 * stored file once the job on the `exports` queue has written it.
 *
 * Deliberately NOT using BelongsToTenant. The platform-wide report has no tenant
 * at all (`tenant_id` null), and a global scope that filters on the current
 * tenant would make those rows invisible to the admin console that owns them.
 * Scoping is therefore explicit at every call site — {@see forTenant} and
 * {@see platformWide} — which also keeps the download endpoint's check readable.
 */
class ReportExport extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'report',
        'format',
        'locale',
        'filters',
        'status',
        'file_path',
        'file_size',
        'row_count',
        'failure_reason',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    protected $attributes = [
        'status' => ExportStatus::Queued->value,
        'locale' => 'ar',
    ];

    protected $casts = [
        'report' => ReportType::class,
        'format' => ExportFormat::class,
        'status' => ExportStatus::class,
        'filters' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** One academy's exports. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Admin-console exports, which belong to no academy. */
    public function scopePlatformWide(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }

    /**
     * Whether the file is there to be streamed right now. A row can say `ready`
     * while its file has been swept off disk by hand, so the caller still has to
     * check the disk — this only answers the question the row can answer.
     */
    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable()
            && $this->file_path !== null
            && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** The name the browser saves it under: report, date, extension. */
    public function downloadName(): string
    {
        $date = ($this->finished_at ?? $this->created_at ?? now())->format('Y-m-d');

        return "{$this->report->value}-report-{$date}.{$this->format->extension()}";
    }
}
