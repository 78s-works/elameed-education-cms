<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A typed content section of a lesson (FR-M04-01). Points at exactly one
 * payload — a MediaAsset (video/pdf) or an Exam (assignment/quiz) — per its
 * `type`. Dependencies referencing this section (as the dependent) live in
 * `dependencies`.
 *
 * @property LessonSectionType $type
 * @property ?PdfKind $pdf_kind
 */
class LessonSection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'lesson_id',
        'type',
        'title',
        'sort_order',
        'media_asset_id',
        'exam_id',
        'pdf_kind',
        'assignment_kind',
        'is_required',
    ];

    protected $attributes = [
        'is_required' => true,
    ];

    protected $casts = [
        'type' => LessonSectionType::class,
        'pdf_kind' => PdfKind::class,
        'assignment_kind' => AssignmentKind::class,
        'sort_order' => 'integer',
        'is_required' => 'boolean',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /** Rules that gate THIS section (this section is the dependent). */
    public function dependencies(): HasMany
    {
        return $this->hasMany(ContentDependency::class, 'section_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
