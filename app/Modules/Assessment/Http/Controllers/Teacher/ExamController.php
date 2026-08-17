<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Http\Requests\ExamRequest;
use App\Modules\Assessment\Http\Resources\ExamResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Teacher authoring of exams (M08), managed from the sidebar — NOT nested under a
 * course. The exam `type` fixes the link and `lesson_id` is auto-filled from it
 * (single source of truth); the client never sets it directly. All exams are
 * tenant-scoped by the BelongsToTenant global scope. (`courses`/units retired — VD §7.)
 *
 * Convention constraints (enforced here, not configured by the teacher):
 *   - a lesson has at most ONE lesson_quiz and ONE homework
 */
class ExamController
{
    /** Filterable list: ?type=&lesson_id= . */
    public function index(Request $request): AnonymousResourceCollection
    {
        $exams = Exam::query()
            ->withCount('questions')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->latest('id')
            ->get();

        return ExamResource::collection($exams);
    }

    public function store(ExamRequest $request): JsonResponse
    {
        $data = $request->validated();
        // A brand-new exam has no questions yet, so it can't be published on create.
        if ($data['is_published'] ?? false) {
            throw new ConflictHttpException('Add questions before publishing this exam.');
        }

        $type = ExamType::from($data['type']);
        $lessonId = $this->resolveLessonLink($type, $data);
        $this->assertUniquePerLink($type, $lessonId, null);

        $exam = new Exam($data);
        $exam->lesson_id = $lessonId;
        $exam->save(); // BelongsToTenant fills tenant_id

        return (new ExamResource($exam->loadCount('questions')))->response()->setStatusCode(201);
    }

    public function show(Exam $exam): ExamResource
    {
        return new ExamResource($exam->loadCount('questions'));
    }

    public function update(ExamRequest $request, Exam $exam): ExamResource
    {
        $data = $request->validated();

        // Don't allow publishing an exam that has no questions.
        $willPublish = array_key_exists('is_published', $data) ? (bool) $data['is_published'] : $exam->is_published;
        if ($willPublish && $exam->questions()->count() === 0) {
            throw new ConflictHttpException('Add questions before publishing this exam.');
        }

        // Re-derive the lesson link when the type or the link target is (re)sent.
        $type = array_key_exists('type', $data) ? ExamType::from($data['type']) : $exam->type;
        if (array_key_exists('type', $data) || array_key_exists('lesson_id', $data)) {
            $lessonId = $this->resolveLessonLink($type, $data + ['lesson_id' => $exam->lesson_id]);
            $this->assertUniquePerLink($type, $lessonId, $exam->id);
            $data['lesson_id'] = $lessonId;
        }

        $exam->update($data);

        return new ExamResource($exam->loadCount('questions'));
    }

    public function destroy(Exam $exam): Response
    {
        $exam->delete(); // soft delete (keeps attempt history)

        return response()->noContent();
    }

    /**
     * Resolve the `lesson_id` link from the exam type. lesson_quiz/homework bind to
     * a lesson (validated tenant-scoped); free_exam links to nothing. (`courses`/
     * units retired — VD §7, so no course/unit derivation.)
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveLessonLink(ExamType $type, array $data): ?int
    {
        if ($type->linksLesson()) {
            return Lesson::query()->findOrFail($data['lesson_id'])->id;
        }

        return null; // free_exam — no link
    }

    /**
     * Enforce the one-per-link convention: a lesson holds at most one lesson_quiz
     * and one homework. free_exam is unlimited.
     */
    private function assertUniquePerLink(ExamType $type, ?int $lessonId, ?int $ignoreExamId): void
    {
        if (! $type->linksLesson()) {
            return; // free_exam — no uniqueness
        }

        $query = Exam::query()->where('type', $type->value)->where('lesson_id', $lessonId);

        if ($ignoreExamId !== null) {
            $query->where('id', '!=', $ignoreExamId);
        }

        if ($query->exists()) {
            $label = match ($type) {
                ExamType::LessonQuiz => 'This lesson already has a quiz.',
                ExamType::Homework => 'This lesson already has a homework.',
                default => 'A conflicting exam already exists.',
            };
            throw new ConflictHttpException($label);
        }
    }
}
