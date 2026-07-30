<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable unit prerequisite: unit `unit_id` stays gated until `trigger`
 * is met on the prerequisite — either another unit's exam (`depends_on_unit_id`)
 * or a specific section (`depends_on_section_id`). Mandatory rows block; optional
 * ones are advisory.
 *
 * @property DependencyTrigger $trigger
 * @property DependencyEnforcement $enforcement
 */
class UnitDependency extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'unit_id',
        'depends_on_unit_id',
        'depends_on_section_id',
        'trigger',
        'enforcement',
    ];

    protected $casts = [
        'trigger' => DependencyTrigger::class,
        'enforcement' => DependencyEnforcement::class,
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function dependsOnUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'depends_on_unit_id');
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
