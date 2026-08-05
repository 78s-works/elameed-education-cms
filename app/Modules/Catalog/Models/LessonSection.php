<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Catalog\Enums\GateRule;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use App\Modules\Catalog\Enums\SectionDelivery;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lesson part (VD change set §7). The `type` selects the payload:
 *   video    — an uploaded lecture MediaAsset (media_asset_id) or YouTube link.
 *   homework — an assignment backed by an Exam (exam_id) + delivery/gate/degree.
 *   quiz     — a quiz backed by an Exam (same, plus an optional duration cap).
 * `access_mode` is constrained ⊆ the lesson's access_mode. Legacy section types
 * (lecture_video/pdf/quiz_solution/hw_solution) still flow through this model.
 *
 * @property LessonSectionType $type
 * @property ?AccessMode $access_mode
 * @property ?SectionDelivery $delivery
 * @property ?GateRule $gate_rule
 * @property ?PdfKind $pdf_kind
 */
class LessonSection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'lesson_id',
        'type',
        'access_mode',
        'delivery',
        'gate_rule',
        'max_tries',
        'title',
        'sort_order',
        'media_asset_id',
        'exam_id',
        'youtube_url',
        'pdf_kind',
        'is_required',
    ];

    protected $attributes = [
        'is_required' => true,
    ];

    protected $casts = [
        'type' => LessonSectionType::class,
        'access_mode' => AccessMode::class,
        'delivery' => SectionDelivery::class,
        'gate_rule' => GateRule::class,
        'pdf_kind' => PdfKind::class,
        'assignment_kind' => AssignmentKind::class,
        'sort_order' => 'integer',
        'max_tries' => 'integer',
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

    /** Teacher pass-overrides granted on this part (LP-D3). */
    public function passOverrides(): HasMany
    {
        return $this->hasMany(PartPassOverride::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
