<?php

namespace App\Modules\Assessment\Services;

use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Centers\Models\CenterExamGrade;
use Illuminate\Support\Collection;

/**
 * One builder for a student's grade history — online exam/homework attempts and
 * paper (in-center) grades, merged newest-first with a shared row shape.
 *
 * It exists so the student's own `/me/results` and the teacher's view of the same
 * student cannot disagree: before this, the student panel stitched its history
 * together from a locally-persisted attempt tracker, so the same account showed
 * different history in a different browser (UX review 26 Aug, weakness #12).
 *
 * Scoping. Both models are tenant- and year-scoped by global scope, and a student
 * request is pinned to that student's own year by the academic-year middleware, so
 * the default path needs no explicit filtering beyond the user. The teacher panel
 * is the exception: it may sit on a different year than the exam a student sat, so
 * it passes `crossYear: true`, which drops the global scopes and re-applies the
 * tenant filter by hand (never dropping tenant isolation).
 */
class StudentResultsQuery
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function for(int $tenantId, int $userId, bool $crossYear = false, int $limit = 100): array
    {
        $rows = $this->onlineAttempts($tenantId, $userId, $crossYear, $limit)
            ->concat($this->paperGrades($tenantId, $userId, $crossYear, $limit))
            ->sortByDesc(fn (array $row) => $row['at'] ?? '')
            ->values();

        $scored = $rows->filter(fn (array $row) => $row['percent'] !== null);

        return [
            'data' => $rows->all(),
            'meta' => [
                'total' => $rows->count(),
                'average_percent' => $scored->count() > 0
                    ? (int) round($scored->avg('percent'))
                    : null,
            ],
        ];
    }

    /** Submitted or graded attempts on online exams and homework. */
    private function onlineAttempts(int $tenantId, int $userId, bool $crossYear, int $limit): Collection
    {
        $attempts = $this->scoped(ExamAttempt::query(), $tenantId, $crossYear)
            ->where('user_id', $userId)
            ->whereIn('status', [AttemptStatus::Graded->value, AttemptStatus::Submitted->value])
            ->latest('id')
            ->limit($limit)
            ->get();

        // Titles are read without the year scope even on the student path: the
        // attempt is already proven to belong to this student, and an exam moved
        // to another year must not turn its own history into blank rows.
        $exams = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $attempts->pluck('exam_id')->filter()->unique())
            ->get(['id', 'uuid', 'title', 'type'])
            ->keyBy('id');

        return $attempts->map(fn (ExamAttempt $attempt) => [
            'kind' => 'online',
            'id' => 'attempt-'.$attempt->id,
            'title' => $exams->get($attempt->exam_id)?->title,
            'exam_type' => $exams->get($attempt->exam_id)?->type?->value,
            'score' => $attempt->score === null ? null : (float) $attempt->score,
            'max_score' => $attempt->max_score === null ? null : (float) $attempt->max_score,
            'percent' => $this->percent($attempt->score, $attempt->max_score),
            'status' => $attempt->status->value,
            // Submitted-but-awaiting-manual-grading: the row has no usable score
            // yet, and the client says so rather than showing a bare dash.
            'needs_manual_grade' => (bool) $attempt->needs_manual_grade,
            'at' => ($attempt->submitted_at ?? $attempt->created_at)?->toIso8601String(),
            'attempt_number' => $attempt->attempt_number,
            // The student's result screen is addressed by exam uuid + attempt id,
            // so the row carries both and the history becomes clickable.
            'exam_uuid' => $exams->get($attempt->exam_id)?->uuid,
            'attempt_id' => $attempt->id,
        ]);
    }

    /** Paper grades typed in at a center. */
    private function paperGrades(int $tenantId, int $userId, bool $crossYear, int $limit): Collection
    {
        return $this->scoped(CenterExamGrade::query(), $tenantId, $crossYear)
            ->where('student_user_id', $userId)
            ->with('center:id,name')
            ->latest('sat_on')
            ->limit($limit)
            ->get()
            ->map(fn (CenterExamGrade $grade) => [
                'kind' => 'center',
                'id' => 'grade-'.$grade->id,
                'title' => $grade->title,
                'exam_type' => null,
                'score' => (float) $grade->score,
                'max_score' => (float) $grade->total_marks,
                'percent' => $this->percent($grade->score, $grade->total_marks),
                'status' => 'graded',
                'needs_manual_grade' => false,
                'at' => $grade->sat_on?->toIso8601String(),
                'center' => $grade->center?->name,
                'note' => $grade->note,
            ]);
    }

    /**
     * Apply the caller's scoping choice. `crossYear` drops the global scopes — the
     * teacher panel may be on another year than the exam — and puts the tenant
     * filter back explicitly, since dropping scopes drops tenant isolation too.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function scoped($query, int $tenantId, bool $crossYear)
    {
        return $crossYear
            ? $query->withoutGlobalScopes()->where('tenant_id', $tenantId)
            : $query->where('tenant_id', $tenantId);
    }

    private function percent(mixed $score, mixed $outOf): ?int
    {
        if ($score === null || (float) $outOf <= 0) {
            return null;
        }

        return (int) round((float) $score / (float) $outOf * 100);
    }
}
