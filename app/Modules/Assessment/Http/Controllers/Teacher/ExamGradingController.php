<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\GradeAttemptRequest;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Services\GradingService;
use App\Modules\Engagement\Services\PointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher grading of exam submissions (M08). Auto-graded objective questions are
 * already scored; this assigns points to the subjective ones and finalises.
 */
class ExamGradingController
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly PointsService $points,
    ) {}

    public function submissions(Request $request, Exam $exam): JsonResponse
    {
        $attempts = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->when($request->boolean('filter.needs_grading'), fn ($q) => $q->where('needs_manual_grade', true))
            ->whereIn('status', ['submitted', 'graded'])
            ->with('user:id,uuid,name,phone')
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'attempt_id' => $a->id,
                'student' => ['uuid' => $a->user?->uuid, 'name' => $a->user?->name, 'phone' => $a->user?->phone],
                'status' => $a->status->value,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'needs_manual_grade' => $a->needs_manual_grade,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $attempts]);
    }

    public function grade(GradeAttemptRequest $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->exam_id === $exam->id, 404);

        $attempt = $this->grading->applyManualGrades($attempt, $request->validated('grades'));

        // Award points once the attempt is fully graded and passing.
        if ($attempt->status->value === 'graded' && $attempt->max_score > 0
            && ($attempt->score / $attempt->max_score * 100) >= $exam->pass_percent) {
            $this->points->award((int) $exam->tenant_id, (int) $attempt->user_id,
                (int) config('gamification.exam_points', 20), 'exam.passed', 'exam', $exam->id);
        }

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'status' => $attempt->status->value,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'needs_manual_grade' => $attempt->needs_manual_grade,
        ]]);
    }
}
