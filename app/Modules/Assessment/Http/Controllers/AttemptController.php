<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Enums\QuestionType;
use App\Modules\Assessment\Http\Requests\SubmitAttemptRequest;
use App\Modules\Assessment\Http\Requests\UploadAttemptFileRequest;
use App\Modules\Assessment\Http\Resources\ExamResource;
use App\Modules\Assessment\Http\Resources\PublicQuestionResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Services\ExamTimeExtensionService;
use App\Modules\Assessment\Services\GradingService;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly ExamTimeExtensionService $timeExtensions,
    ) {}

    /**
     * Published, in-window exams the student can reach: every free_exam, plus any
     * exam covered by a grant (whole course / free course / unit / lesson / direct
     * exam). Optional ?lesson_id= narrows to one lesson (the course player's quiz +
     * homework). Discovery only — start-time re-checks access via hasExamAccess.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $grants = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $request->user()->getKey())
            ->grantsAccess()->get(['course_id', 'unit_id', 'lesson_id', 'exam_id']);

        $courseIds = $grants->pluck('course_id')->filter()->unique()->values()->all();
        $unitIds = $grants->pluck('unit_id')->filter()->unique()->values()->all();
        $lessonIds = $grants->pluck('lesson_id')->filter()->unique()->values()->all();
        $examIds = $grants->pluck('exam_id')->filter()->unique()->values()->all();
        $freeCourseIds = Course::query()->where('is_free', true)->pluck('id')->all();

        $exams = Exam::query()
            ->withCount('questions')
            ->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->where(function ($q) use ($courseIds, $unitIds, $lessonIds, $examIds, $freeCourseIds): void {
                $q->where('type', ExamType::FreeExam->value)
                    ->orWhereIn('course_id', array_merge($courseIds, $freeCourseIds))
                    ->orWhereIn('unit_id', $unitIds)
                    ->orWhereIn('lesson_id', $lessonIds)
                    ->orWhereIn('id', $examIds);
            })
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

        // Effective duration includes any per-student granted time extension (R6).
        $duration = $this->timeExtensions->effectiveDuration((int) $exam->tenant_id, (int) $userId, $exam);

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'duration_min' => $duration,
            'questions' => PublicQuestionResource::collection($questions)->resolve($request),
        ]]);
    }

    /** Student requests extra time on this exam/quiz (doc 11 R6). */
    public function requestExtension(Request $request, Exam $exam): JsonResponse
    {
        $this->assertPlayable($request, $exam);

        $minutes = $request->integer('minutes') ?: null;
        $row = $this->timeExtensions->request(
            (int) $exam->tenant_id,
            (int) $request->user()->getKey(),
            $exam,
            $minutes,
        );

        return response()->json(['data' => [
            'id' => $row->id,
            'status' => $row->status->value,
            'requested_minutes' => $row->requested_minutes,
        ]], 201);
    }

    public function submit(SubmitAttemptRequest $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        $this->assertSubmittable($request, $exam, $attempt);

        $exam->load('questions');
        $graded = $this->grading->gradeSubmission(
            $exam,
            $request->validated('answers'),
            $attempt->answers ?? [], // keep files uploaded before submit
        );

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

    /**
     * Upload a file answer for a `file`-type question on an in-progress attempt.
     * The file is stored privately and recorded on the attempt server-side; the
     * student re-submits the attempt as usual and the file is preserved.
     */
    public function uploadFile(UploadAttemptFileRequest $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        $this->assertOwnedInProgress($request, $exam, $attempt);

        $question = $exam->questions()->where('id', $request->integer('question_id'))->first();
        abort_if($question === null, 404, 'Question not found on this exam.');

        if ($question->type !== QuestionType::File) {
            throw new ConflictHttpException('This question does not accept a file answer.');
        }

        $disk = $this->uploadDisk();
        $file = $request->file('file');
        $answers = $attempt->answers ?? [];

        // Replace any file the student uploaded earlier for this question.
        $previous = $answers[$question->id]['file']['path'] ?? null;
        if ($previous !== null) {
            Storage::disk($disk)->delete($previous);
        }

        $path = $file->store("assignments/{$exam->tenant_id}/{$attempt->id}", $disk);

        $answers[$question->id] = [
            'answer' => $file->getClientOriginalName(),
            'file' => [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ],
            'awarded' => null,
            'is_correct' => null,
        ];

        $attempt->update(['answers' => $answers, 'needs_manual_grade' => true]);

        return response()->json(['data' => [
            'question_id' => $question->id,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]]);
    }

    private function uploadDisk(): string
    {
        return (string) config('assessment.upload_disk', 'local');
    }

    public function result(Request $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        $this->assertOwned($request, $exam, $attempt);

        return response()->json(['data' => $this->present($exam, $attempt)]);
    }

    /** Download the teacher's corrected/annotated file for the student's own attempt. */
    public function downloadCorrectedFile(Request $request, Exam $exam, ExamAttempt $attempt): StreamedResponse
    {
        $this->assertOwned($request, $exam, $attempt);

        $file = $attempt->corrected_file;
        abort_if($file === null || empty($file['path']), 404, 'No corrected file for this attempt.');

        $disk = $this->uploadDisk();
        $path = (string) $file['path'];

        // Corrected files live only under assignments/; reject anything else.
        abort_unless(str_starts_with($path, 'assignments/') && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path, $file['name'] ?? basename($path));
    }

    // — guards —

    private function assertPlayable(Request $request, Exam $exam): void
    {
        if (! $exam->isOpen()) {
            throw new ConflictHttpException('This exam is not open.');
        }
        // Exams are never gated by content/dependencies in the convention model —
        // access only requires a grant covering the exam (a free_exam bypasses even
        // that; see EnrollmentService::hasExamAccess).
        if (! $this->enrollments->hasExamAccess((int) $exam->tenant_id, $request->user()->getKey(), $exam)) {
            throw new AccessDeniedHttpException('You do not have access to this exam.');
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

    /**
     * Guard the submit path: the attempt must be owned + in-progress, the exam's
     * submission window (ends_at) must still be open, and — for a timed exam — the
     * per-student duration (incl. any granted extension) must not have elapsed.
     * Enforced server-side so the client-side countdown can't be bypassed (C1/H1).
     */
    private function assertSubmittable(Request $request, Exam $exam, ExamAttempt $attempt): void
    {
        $this->assertOwnedInProgress($request, $exam, $attempt);

        // Homework/exam submission window (ends_at) must still be open.
        if ($exam->ends_at !== null && now()->greaterThan($exam->ends_at)) {
            throw new ConflictHttpException('The submission window for this exam has closed.');
        }

        // Timed exams: reject a submission made after the attempt's deadline.
        $duration = $this->timeExtensions->effectiveDuration(
            (int) $exam->tenant_id, (int) $request->user()->getKey(), $exam
        );
        if ($duration !== null && $attempt->started_at !== null
            && now()->greaterThan($attempt->started_at->copy()->addMinutes($duration))) {
            throw new ConflictHttpException('The time for this attempt has expired.');
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

        // Teacher's written feedback + corrected/annotated file (upload homework),
        // surfaced to the student alongside their grade once attached.
        if ($attempt->feedback !== null) {
            $data['feedback'] = $attempt->feedback;
        }
        $corrected = $attempt->corrected_file;
        if (is_array($corrected) && ! empty($corrected['path'])) {
            $data['corrected_file'] = [
                'name' => $corrected['name'] ?? null,
                'size' => $corrected['size'] ?? null,
            ];
        }

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
