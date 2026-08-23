<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Assessment\Enums\ExamGradingMode;
use App\Modules\Assessment\Enums\ExamMode;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\SectionDelivery;
use App\Modules\Catalog\Http\Requests\LessonSectionRequest;
use App\Modules\Catalog\Http\Resources\LessonSectionResource;
use App\Modules\Catalog\Http\Resources\PartPassOverrideResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * /teacher/lessons/{lesson}/sections (VD change set §8.3) — the lesson's ordered
 * parts. A quiz/homework part is backed by an Exam row (created/updated here) that
 * holds its degree + grading config; the part row holds access_mode, delivery,
 * gate_rule, and max_tries. Lesson + section bind by id under the year/tenant
 * scope, so foreign ids 404.
 */
class LessonSectionController
{
    public function index(Lesson $lesson): AnonymousResourceCollection
    {
        return LessonSectionResource::collection(
            $lesson->sections()->ordered()->with(['mediaAsset', 'exam'])->get(),
        );
    }

    public function store(LessonSectionRequest $request, Lesson $lesson): JsonResponse
    {
        $section = DB::transaction(function () use ($request, $lesson): LessonSection {
            $attributes = $request->sectionAttributes();
            $type = LessonSectionType::from($attributes['type']);

            if ($type->backsExam()) {
                $exam = $this->makeBackingExam($lesson, $type, $request);
                $attributes['exam_id'] = $exam->id;
                // delivery is derived from the exam's mode (single source of truth).
                $attributes['delivery'] = $this->deliveryForExam($exam);
            }

            return $lesson->sections()->create($attributes);
        });

        return (new LessonSectionResource($section->load(['mediaAsset', 'exam'])))
            ->response()->setStatusCode(201);
    }

    public function update(LessonSectionRequest $request, Lesson $lesson, LessonSection $section): LessonSectionResource
    {
        $this->assertOwnership($lesson, $section);

        DB::transaction(function () use ($request, $lesson, $section): void {
            $attributes = $request->sectionAttributes();
            $type = LessonSectionType::from($attributes['type']);

            if ($type->backsExam()) {
                // Reuse the existing backing exam if present; otherwise mint one.
                $exam = $section->exam_id ? Exam::find($section->exam_id) : null;

                if ($exam !== null) {
                    // Only the degree fields the part dialog still owns — never the
                    // exam's mode/grading/published state (those are exam-page owned).
                    $exam->update($this->examAttributes($type, $request));
                } else {
                    $exam = $this->makeBackingExam($lesson, $type, $request);
                }

                $attributes['exam_id'] = $exam->id;
                // Keep the part's delivery in step with the exam's mode; the teacher
                // changes the mode itself on the exam settings page, not here.
                $attributes['delivery'] = $this->deliveryForExam($exam->fresh());
            } else {
                $attributes['exam_id'] = null; // a video part carries no exam
                $attributes['delivery'] = null;
            }

            $section->update($attributes);
        });

        return new LessonSectionResource($section->fresh()->load(['mediaAsset', 'exam']));
    }

    public function destroy(Lesson $lesson, LessonSection $section): Response
    {
        $this->assertOwnership($lesson, $section);
        $section->delete();

        return response()->noContent();
    }

    /** Reorder parts: { order: [id, …] } → sort_order = position. */
    public function reorder(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $ids = $lesson->sections()->pluck('id')->all();

        // Every id must belong to this lesson (and vice-versa is not required —
        // a partial reorder is allowed, but no foreign id may sneak in).
        foreach ($validated['order'] as $id) {
            if (! in_array($id, $ids, true)) {
                throw ValidationException::withMessages(['order' => "Section {$id} does not belong to this lesson."]);
            }
        }

        DB::transaction(function () use ($validated, $lesson): void {
            foreach (array_values($validated['order']) as $position => $id) {
                $lesson->sections()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return LessonSectionResource::collection(
            $lesson->sections()->ordered()->with(['mediaAsset', 'exam'])->get(),
        );
    }

    /** Grant a manual pass on a must_pass part (LP-D3). Duplicate → 409. */
    public function storePassOverride(Request $request, Lesson $lesson, LessonSection $section): JsonResponse
    {
        $this->assertOwnership($lesson, $section);

        $validated = $request->validate([
            'user_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::where('uuid', $validated['user_id'])->first();
        if ($student === null) {
            throw ValidationException::withMessages(['user_id' => 'No such student.']);
        }

        if ($section->passOverrides()->where('user_id', $student->id)->exists()) {
            throw new ConflictHttpException('This student already has a pass-override for this part.');
        }

        $override = $section->passOverrides()->create([
            'user_id' => $student->id,
            'granted_by' => $request->user()->id,
            'granted_at' => now(),
            'note' => $validated['note'] ?? null,
        ]);

        return (new PartPassOverrideResource($override->load(['student', 'grantedBy'])))
            ->response()->setStatusCode(201);
    }

    /** Revoke a manual pass. Idempotent — a missing row still returns 204. */
    public function destroyPassOverride(Lesson $lesson, LessonSection $section, User $user): Response
    {
        $this->assertOwnership($lesson, $section);
        $section->passOverrides()->where('user_id', $user->id)->delete();

        return response()->noContent();
    }

    private function assertOwnership(Lesson $lesson, LessonSection $section): void
    {
        abort_unless($section->lesson_id === $lesson->id, 404);
    }

    /** Build the exam that backs a quiz/homework part. */
    private function makeBackingExam(Lesson $lesson, LessonSectionType $type, LessonSectionRequest $request): Exam
    {
        // Degree fields from the part payload, plus the create-only defaults: the
        // delivery method (mode) and grading are set later from the exam settings
        // page, so a fresh backing exam is a standard, manually-graded one.
        $exam = new Exam(array_merge($this->examAttributes($type, $request), [
            'lesson_id' => $lesson->id,
            'type' => ($type === LessonSectionType::Quiz ? ExamType::LessonQuiz : ExamType::Homework)->value,
            'mode' => ExamMode::Standard->value,
            'grading_mode' => ExamGradingMode::Manual->value,
            'is_published' => true,
        ]));
        $exam->save(); // tenant_id via BelongsToTenant, uuid via HasUuids

        return $exam;
    }

    /**
     * The degree columns the part dialog still owns — safe to write on both create
     * and update (they never touch the exam's mode/grading/published state).
     *
     * @return array<string, mixed>
     */
    private function examAttributes(LessonSectionType $type, LessonSectionRequest $request): array
    {
        $degree = $request->examAttributes();

        $passValue = (float) ($degree['pass_value'] ?? 0);
        $total = isset($degree['total_marks']) ? (float) $degree['total_marks'] : null;
        $legacyPercent = ($degree['pass_mode'] ?? 'percent') === 'marks'
            ? ($total > 0 ? (int) round($passValue / $total * 100) : 50)
            : (int) round($passValue);

        return [
            'title' => $request->input('name') ?: ucfirst($type->value),
            'pass_percent' => max(0, min(100, $legacyPercent)),
            'pass_mode' => $degree['pass_mode'] ?? 'percent',
            'pass_value' => $degree['pass_value'] ?? null,
            'total_marks' => $degree['total_marks'] ?? null,
            'duration_min' => $degree['duration_min'] ?? null,
        ];
    }

    /** The part's delivery, derived from its backing exam's mode (single source). */
    private function deliveryForExam(Exam $exam): string
    {
        return ($exam->mode === ExamMode::BubbleSheet
            ? SectionDelivery::BubbleSheet
            : SectionDelivery::ImageUpload)->value;
    }
}
