<?php

namespace App\Support\Traits;

use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Services\AcademicYearContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to content models that live under an academic year (courses, packages,
 * … — wired in later phases). Mirrors BelongsToTenant:
 *
 *   1. A global scope constraining queries to the context academic year.
 *   2. A creating hook that auto-fills `academic_year_id` from the context.
 *
 * Both no-op when no context year is set (AcademicYearContext::hasYear() === false),
 * so the academic-year CRUD itself — and every request without the `academic-year`
 * middleware — is naturally exempt.
 */
trait BelongsToAcademicYear
{
    public static function bootBelongsToAcademicYear(): void
    {
        static::addGlobalScope('academic_year', function (Builder $builder): void {
            $context = app(AcademicYearContext::class);

            if ($context->hasYear()) {
                $model = $builder->getModel();
                $builder->where(
                    $model->qualifyColumn($model->getAcademicYearIdColumn()),
                    $context->id(),
                );
            }
        });

        static::creating(function (Model $model): void {
            $context = app(AcademicYearContext::class);
            $column = $model->getAcademicYearIdColumn();

            if ($context->hasYear() && empty($model->getAttribute($column))) {
                $model->setAttribute($column, $context->id());
            }
        });
    }

    public function getAcademicYearIdColumn(): string
    {
        return 'academic_year_id';
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, $this->getAcademicYearIdColumn());
    }
}
