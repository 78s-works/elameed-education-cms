<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unlock rule: section `section_id` stays locked until `trigger` is met on
 * `depends_on_section_id`. Mandatory rules block; optional rules only inform.
 *
 * @property DependencyTrigger $trigger
 * @property DependencyEnforcement $enforcement
 */
class ContentDependency extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'section_id',
        'academic_year_id',
        'depends_on_section_id',
        'trigger',
        'enforcement',
    ];

    protected $casts = [
        'trigger' => DependencyTrigger::class,
        'enforcement' => DependencyEnforcement::class,
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(LessonSection::class, 'section_id');
    }

    public function dependsOnSection(): BelongsTo
    {
        return $this->belongsTo(LessonSection::class, 'depends_on_section_id');
    }

    public function isMandatory(): bool
    {
        return $this->enforcement === DependencyEnforcement::Mandatory;
    }
}
