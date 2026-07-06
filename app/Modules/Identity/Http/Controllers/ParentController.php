<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parent portal (M13). A parent (role:parent) sees only the children linked to
 * them in this academy, and each child's progress + results — read-only.
 */
class ParentController
{
    public function __construct(private readonly TenantContext $context) {}

    public function children(Request $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $children = ParentLink::query()
            ->where('parent_user_id', $request->user()->getKey())
            ->with('student:id,uuid,name,phone')
            ->get()
            ->map(fn (ParentLink $l) => [
                'uuid' => $l->student?->uuid,
                'name' => $l->student?->name,
                'phone' => $l->student?->phone,
                'relation' => $l->relation,
                'lessons_completed' => LessonProgress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('user_id', $l->student_user_id)
                    ->whereNotNull('completed_at')->count(),
            ]);

        return response()->json(['data' => $children]);
    }

    public function progress(Request $request, User $student): JsonResponse
    {
        $this->assertMyChild($request, $student);

        $rows = LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $this->context->tenantOrFail()->getKey())
            ->where('user_id', $student->getKey())
            ->with('lesson:id,title')
            ->latest('updated_at')
            ->get()
            ->map(fn (LessonProgress $p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'watch_percent' => $p->watch_percent,
                'completed' => $p->completed_at !== null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function results(Request $request, User $student): JsonResponse
    {
        $this->assertMyChild($request, $student);

        $rows = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $this->context->tenantOrFail()->getKey())
            ->where('user_id', $student->getKey())
            ->whereIn('status', ['submitted', 'graded'])
            ->with('exam:id,title')
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'exam' => $a->exam?->title,
                'status' => $a->status->value,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** The target must be a child linked to the authenticated parent in this tenant. */
    private function assertMyChild(Request $request, User $student): void
    {
        $linked = ParentLink::query()
            ->where('parent_user_id', $request->user()->getKey())
            ->where('student_user_id', $student->getKey())
            ->exists();

        abort_unless($linked, 404, 'Child not found.');
    }
}
