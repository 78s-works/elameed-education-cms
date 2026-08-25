<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Centers\Models\CenterSession;
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
            ->latest('updated_at')
            ->get();

        // Titles resolved WITHOUT the year scope: this student's own lessons must
        // read correctly whichever year the panel has selected.
        $titles = Lesson::withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('lesson_id')->filter()->unique())
            ->pluck('title', 'id');

        $rows = $rows->map(fn (LessonProgress $p) => [
            'lesson_id' => $p->lesson_id,
            'lesson_title' => $titles[$p->lesson_id] ?? null,
            'watch_percent' => $p->watch_percent,
            // Real seconds watched — the study tab sums them into a total.
            'watch_seconds' => (int) $p->watch_seconds,
            'last_position_sec' => $p->last_position_sec,
            'completed' => $p->completed_at !== null,
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * The student's center attendance, newest first — day-attendance rows and
     * session/part check-ins alike. Not year-scoped: the teacher is looking at
     * one student's whole history on this page, not the active year's roster.
     */
    public function attendance(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $records = AttendanceRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->with('center:id,name')
            ->orderByDesc('attended_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Sessions are year-scoped, so they are fetched scope-free — otherwise a
        // check-in for another year's session would print with no session name.
        $sessions = CenterSession::withoutGlobalScopes()
            ->whereIn('id', $records->pluck('center_session_id')->filter()->unique())
            ->get(['id', 'name', 'session_at'])
            ->keyBy('id');

        $rows = $records->map(fn (AttendanceRecord $r) => [
            'id' => $r->id,
            'attended_on' => $r->attended_on?->toDateString(),
            'status' => $r->status,
            'source' => $r->source,
            'center' => $r->center?->name,
            'session' => $sessions->get($r->center_session_id)?->name,
            'session_at' => $sessions->get($r->center_session_id)?->session_at?->toIso8601String(),
            'access_expires_at' => $r->access_expires_at?->toIso8601String(),
            'note' => $r->note,
        ]);

        $present = $rows->where('status', 'present')->count();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'present' => $present,
                'percent' => $rows->count() > 0 ? (int) round($present / $rows->count() * 100) : null,
            ],
        ]);
    }

    /**
     * Every score this student has: auto-graded online attempts and typed-in
     * paper (center) grades, merged newest-first and tagged with `kind` so the
     * panel can show one table.
     */
    public function examResults(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $uid = $student->getKey();

        $attempts = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $uid)
            ->whereIn('status', [AttemptStatus::Graded->value, AttemptStatus::Submitted->value])
            ->latest('id')
            ->limit(100)
            ->get();

        // Same reason as above: the exam may belong to a year the panel isn't on.
        $exams = Exam::withoutGlobalScopes()
            ->whereIn('id', $attempts->pluck('exam_id')->filter()->unique())
            ->get(['id', 'title', 'type'])
            ->keyBy('id');

        $online = $attempts
            ->map(fn (ExamAttempt $a) => [
                'kind' => 'online',
                'id' => 'attempt-'.$a->id,
                'title' => $exams->get($a->exam_id)?->title,
                'exam_type' => $exams->get($a->exam_id)?->type?->value,
                'score' => $a->score === null ? null : (float) $a->score,
                'max_score' => $a->max_score === null ? null : (float) $a->max_score,
                'percent' => ($a->max_score > 0 && $a->score !== null)
                    ? (int) round((float) $a->score / (float) $a->max_score * 100)
                    : null,
                'status' => $a->status->value,
                'at' => ($a->submitted_at ?? $a->created_at)?->toIso8601String(),
                'attempt_number' => $a->attempt_number,
            ]);

        $paper = CenterExamGrade::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('student_user_id', $uid)
            ->with('center:id,name')
            ->latest('sat_on')
            ->limit(100)
            ->get()
            ->map(fn (CenterExamGrade $g) => [
                'kind' => 'center',
                'id' => 'grade-'.$g->id,
                'title' => $g->title,
                'exam_type' => null,
                'score' => (float) $g->score,
                'max_score' => (float) $g->total_marks,
                'percent' => (float) $g->total_marks > 0
                    ? (int) round((float) $g->score / (float) $g->total_marks * 100)
                    : null,
                'status' => 'graded',
                'at' => $g->sat_on?->toIso8601String(),
                'center' => $g->center?->name,
                'note' => $g->note,
            ]);

        $rows = $online->concat($paper)
            ->sortByDesc(fn ($r) => $r['at'] ?? '')
            ->values();

        $scored = $rows->filter(fn ($r) => $r['percent'] !== null);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'average_percent' => $scored->count() > 0
                    ? (int) round($scored->avg('percent'))
                    : null,
            ],
        ]);
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
