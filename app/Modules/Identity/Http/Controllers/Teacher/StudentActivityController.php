<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Commerce\Models\Order;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\NotifyStudentRequest;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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

    /** A merged, most-recent-first activity timeline: logins, playback, orders, exams. */
    public function history(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $uid = $student->getKey();
        $events = new Collection;

        $for = fn (string $model) => $model::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $uid);

        $for(LoginAttempt::class)->latest()->limit(50)->get()->each(fn ($r) => $events->push([
            'type' => 'login', 'at' => $r->created_at?->toIso8601String(),
            'meta' => ['success' => (bool) $r->success, 'ip' => $r->ip],
        ]));

        $for(PlaybackSession::class)->latest('issued_at')->limit(50)->get()->each(fn ($r) => $events->push([
            'type' => 'playback', 'at' => $r->issued_at?->toIso8601String(),
            'meta' => ['lesson_id' => $r->lesson_id, 'ip' => $r->ip, 'device' => $r->device_fingerprint],
        ]));

        $for(Order::class)->latest()->limit(50)->get()->each(fn ($r) => $events->push([
            'type' => 'order', 'at' => $r->created_at?->toIso8601String(),
            'meta' => ['uuid' => $r->uuid, 'status' => $r->status, 'total_minor' => $r->total_minor],
        ]));

        $for(ExamAttempt::class)->latest()->limit(50)->get()->each(fn ($r) => $events->push([
            'type' => 'exam_attempt', 'at' => ($r->submitted_at ?? $r->created_at)?->toIso8601String(),
            'meta' => ['exam_id' => $r->exam_id, 'status' => $r->status, 'score' => $r->score],
        ]));

        $timeline = $events->filter(fn ($e) => $e['at'] !== null)->sortByDesc('at')->take(100)->values();

        return response()->json(['data' => $timeline]);
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
