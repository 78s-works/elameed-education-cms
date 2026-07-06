<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Http\Requests\SubmitAttemptRequest;
use App\Modules\Assessment\Http\Resources\ExamResource;
use App\Modules\Assessment\Http\Resources\PublicQuestionResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Services\GradingService;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Student side of exams (M08): discover, start, submit (auto-graded), see result.
 * Access requires an active enrollment in the exam's course.
 */
class AttemptController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
        private readonly GradingService $grading,
        private readonly PointsService $points,
    ) {}

    /** Published, in-window exams for courses the student is enrolled in. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $courseIds = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $request->user()->getKey())
            ->grantsAccess()->pluck('course_id')->filter()->unique();

        $exams = Exam::query()
            ->whereIn('course_id', $courseIds)
            ->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest('id')
            ->get();

        return ExamResource::collection($exams);
    }

    /** Start (or resume) an attempt; returns the questions (no answer key). */
    public function start(Request $request, Exam $exam): JsonResponse
    {
        $this->assertPlayable($request, $exam);

        $userId = $request->user()->getKey();

        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)->where('user_id', $userId)
            ->where('status', AttemptStatus::InProgress->value)
            ->first();

        if ($attempt === null) {
            $count = ExamAttempt::query()->where('exam_id', $exam->id)->where('user_id', $userId)->count();

            if ($exam->attempts_allowed > 0 && $count >= $exam->attempts_allowed) {
                throw new ConflictHttpException('No attempts remaining for this exam.');
            }

            $attempt = new ExamAttempt([
                'exam_id' => $exam->id, 'user_id' => $userId,
                'attempt_number' => $count + 1, 'started_at' => now(),
                'status' => AttemptStatus::InProgress->value,
            ]);
            $attempt->save();
        }

        $questions = $exam->questions()->orderBy('sort_order')->orderBy('id')->get();
        if ($exam->question_order === 'random') {
            $questions = $questions->shuffle();
        }

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'duration_min' => $exam->duration_min,
            'questions' => PublicQuestionResource::collection($questions)->resolve($request),
        ]]);
    }

    public function submit(SubmitAttemptRequest $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        $this->assertOwnedInProgress($request, $exam, $attempt);

        $exam->load('questions');
        $graded = $this->grading->gradeSubmission($exam, $request->validated('answers'));

        $attempt->update([
            'answers' => $graded['answers'],
            'score' => $graded['score'],
            'max_score' => $graded['max_score'],
            'needs_manual_grade' => $graded['needs_manual'],
            'status' => $graded['needs_manual'] ? AttemptStatus::Submitted->value : AttemptStatus::Graded->value,
            'submitted_at' => now(),
        ]);

        // Award points if fully graded on submit and passed (idempotent per exam).
        if (! $graded['needs_manual'] && $graded['max_score'] > 0
            && ($graded['score'] / $graded['max_score'] * 100) >= $exam->pass_percent) {
            $this->points->award((int) $exam->tenant_id, $request->user()->getKey(),
                (int) config('gamification.exam_points', 20), 'exam.passed', 'exam', $exam->id);
        }

        return response()->json(['data' => $this->present($exam, $attempt->fresh())]);
    }

    public function result(Request $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        $this->assertOwned($request, $exam, $attempt);

        return response()->json(['data' => $this->present($exam, $attempt)]);
    }

    // — guards —

    private function assertPlayable(Request $request, Exam $exam): void
    {
        if (! $exam->isOpen()) {
            throw new ConflictHttpException('This exam is not open.');
        }
        if ($exam->course === null
            || ! $this->enrollments->hasAccess((int) $exam->tenant_id, $request->user()->getKey(), $exam->course)) {
            throw new AccessDeniedHttpException('You do not have access to this exam.');
        }
        $this->assertDependencyMet($request, $exam);
    }

    private function assertDependencyMet(Request $request, Exam $exam): void
    {
        if ($exam->depends_on_exam_id === null) {
            return;
        }

        $dep = Exam::query()->find($exam->depends_on_exam_id);
        $passed = $dep !== null && ExamAttempt::query()
            ->where('exam_id', $dep->id)->where('user_id', $request->user()->getKey())
            ->where('status', AttemptStatus::Graded->value)->get()
            ->contains(fn ($a) => $a->max_score > 0 && ($a->score / $a->max_score * 100) >= $dep->pass_percent);

        if (! $passed) {
            throw new AccessDeniedHttpException('Complete the prerequisite exam first.');
        }
    }

    private function assertOwned(Request $request, Exam $exam, ExamAttempt $attempt): void
    {
        abort_unless($attempt->exam_id === $exam->id && $attempt->user_id === $request->user()->getKey(), 404);
    }

    private function assertOwnedInProgress(Request $request, Exam $exam, ExamAttempt $attempt): void
    {
        $this->assertOwned($request, $exam, $attempt);
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new ConflictHttpException('This attempt has already been submitted.');
        }
    }

    /** Shape a result, honouring result_visibility + show_answers. */
    private function present(Exam $exam, ExamAttempt $attempt): array
    {
        $scoreVisible = $this->scoreVisible($exam, $attempt);

        $data = [
            'attempt_id' => $attempt->id,
            'status' => $attempt->status->value,
            'needs_manual_grade' => $attempt->needs_manual_grade,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
        ];

        if ($scoreVisible) {
            $data['score'] = $attempt->score;
            $data['max_score'] = $attempt->max_score;
            $data['passed'] = $attempt->max_score > 0
                ? ($attempt->score / $attempt->max_score * 100) >= $exam->pass_percent
                : null;

            if ($exam->show_answers) {
                $data['review'] = $this->review($exam, $attempt);
            }
        }

        return $data;
    }

    private function scoreVisible(Exam $exam, ExamAttempt $attempt): bool
    {
        if ($attempt->status === AttemptStatus::InProgress) {
            return false;
        }

        return match ($exam->result_visibility) {
            'after_close' => $attempt->status === AttemptStatus::Graded || ($exam->ends_at !== null && $exam->ends_at->isPast()),
            'manual' => $attempt->status === AttemptStatus::Graded,
            default => true, // immediate
        };
    }

    /** Per-question review with the correct key (only when show_answers is on). */
    private function review(Exam $exam, ExamAttempt $attempt): array
    {
        $answers = $attempt->answers ?? [];

        return $exam->questions->map(fn ($q) => [
            'question_id' => $q->id,
            'your_answer' => $answers[$q->id]['answer'] ?? null,
            'awarded' => $answers[$q->id]['awarded'] ?? null,
            'is_correct' => $answers[$q->id]['is_correct'] ?? null,
            'correct' => $q->correct,
            'points' => $q->points,
        ])->values()->all();
    }
}
