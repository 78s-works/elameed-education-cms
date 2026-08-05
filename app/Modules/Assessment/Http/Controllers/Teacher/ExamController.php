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
 * course. The exam `type` fixes the link and course_id/unit_id/lesson_id are
 * auto-filled from it (single source of truth); the client never sets them
 * directly. All exams are tenant-scoped by the BelongsToTenant global scope.
 *
 * Convention constraints (enforced here, not configured by the teacher):
 *   - a lesson has at most ONE lesson_quiz and ONE homework
 *   - a unit has at most ONE unit_exam
 */
class ExamController
{
    /** Filterable list: ?type=&course_id=&unit_id=&lesson_id= . */
    public function index(Request $request): AnonymousResourceCollection
    {
        $exams = Exam::query()
            ->withCount('questions')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('unit_id'), fn ($q) => $q->where('unit_id', $request->integer('unit_id')))
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
        $links = $this->resolveLinks($type, $data);
        $this->assertUniquePerLink($type, $links, null);

        $exam = new Exam($data);
        $exam->course_id = $links['course_id'];
        $exam->unit_id = $links['unit_id'];
        $exam->lesson_id = $links['lesson_id'];
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

        // Re-derive links when the type or the link target is (re)sent.
        $type = array_key_exists('type', $data) ? ExamType::from($data['type']) : $exam->type;
        if (array_key_exists('type', $data) || array_key_exists('lesson_id', $data) || array_key_exists('unit_id', $data)) {
            $links = $this->resolveLinks($type, $data + [
                'lesson_id' => $exam->lesson_id,
                'unit_id' => $exam->unit_id,
            ]);
            $this->assertUniquePerLink($type, $links, $exam->id);
            $data['course_id'] = $links['course_id'];
            $data['unit_id'] = $links['unit_id'];
            $data['lesson_id'] = $links['lesson_id'];
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
     * Resolve course_id/unit_id/lesson_id from the exam type + its link input.
     * lesson_quiz/homework derive course+unit from the lesson; free_exam links to
     * nothing. `unit_exam` is retired (Unit removed, VD §7): the `unit_id` it may
     * carry is a dormant passthrough scalar, no longer resolved against a table.
     *
     * @param  array<string, mixed>  $data
     * @return array{course_id: ?int, unit_id: ?int, lesson_id: ?int}
     */
    private function resolveLinks(ExamType $type, array $data): array
    {
        if ($type->linksLesson()) {
            $lesson = Lesson::query()->findOrFail($data['lesson_id']);

            return ['course_id' => $lesson->course_id, 'unit_id' => $lesson->unit_id, 'lesson_id' => $lesson->id];
        }

        if ($type->linksUnit()) {
            $unitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;

            return ['course_id' => null, 'unit_id' => $unitId, 'lesson_id' => null];
        }

        // free_exam — no links.
        return ['course_id' => null, 'unit_id' => null, 'lesson_id' => null];
    }

    /**
     * Enforce the one-per-link convention: a lesson holds at most one lesson_quiz
     * and one homework; a unit holds at most one unit_exam. free_exam is unlimited.
     *
     * @param  array{course_id: ?int, unit_id: ?int, lesson_id: ?int}  $links
     */
    private function assertUniquePerLink(ExamType $type, array $links, ?int $ignoreExamId): void
    {
        $query = Exam::query()->where('type', $type->value);

        if ($type->linksLesson()) {
            $query->where('lesson_id', $links['lesson_id']);
        } elseif ($type->linksUnit()) {
            $query->where('unit_id', $links['unit_id']);
        } else {
            return; // free_exam — no uniqueness
        }

        if ($ignoreExamId !== null) {
            $query->where('id', '!=', $ignoreExamId);
        }

        if ($query->exists()) {
            $label = match ($type) {
                ExamType::LessonQuiz => 'This lesson already has a quiz.',
                ExamType::Homework => 'This lesson already has a homework.',
                ExamType::UnitExam => 'This unit already has an exam.',
                default => 'A conflicting exam already exists.',
            };
            throw new ConflictHttpException($label);
        }
    }
}
