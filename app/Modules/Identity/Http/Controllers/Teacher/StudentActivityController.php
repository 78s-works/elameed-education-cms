<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\NotifyStudentRequest;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Teacher view of a student's learning activity + direct messaging.
 */
class StudentActivityController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly NotificationService $notifications,
    ) {}

    public function progress(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $rows = LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->with('lesson:id,title,course_id')
            ->latest('updated_at')
            ->get()
            ->map(fn (LessonProgress $p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'watch_percent' => $p->watch_percent,
                'last_position_sec' => $p->last_position_sec,
                'completed' => $p->completed_at !== null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function notify(NotifyStudentRequest $request, User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $this->notifications->inApp($tenantId, $student->getKey(), 'teacher.message', [
            'title' => $request->validated('title'),
            'message' => $request->validated('message'),
        ]);

        return response()->json(['data' => ['message' => __('Notification sent.')]], 201);
    }
}
