<?php

namespace App\Modules\Identity\Http\Controllers\Teacher\Concerns;

use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Media\Models\PlaybackSession;
use Illuminate\Support\Carbon;

/**
 * The at-a-glance numbers behind the student page's overview tab: how much of
 * their content they finished, how often they showed up, how they scored, when
 * they were last seen, and what their access + devices look like right now.
 *
 * Every figure is derived from rows that already exist — nothing is estimated.
 * A metric with no underlying rows is `null` (not 0) so the panel can say
 * "no data" instead of implying a real zero.
 *
 * `subscriptionState()` is deliberately NOT part of `overviewMetrics()`: access
 * rights are not activity data, so the caller publishes it on its own permission
 * rather than behind `students.activity.view`.
 */
trait BuildsStudentOverview
{
    /** @return array<string, mixed> */
    protected function overviewMetrics(int $tenantId, int $userId): array
    {
        $lessonsGranted = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNotNull('lesson_id')
            ->distinct()->count('lesson_id');

        $lessonsCompleted = LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereNotNull('completed_at')->count();

        $attendance = $this->attendanceTotals($tenantId, $userId);
        $exams = $this->examTotals($tenantId, $userId);

        return [
            'lessons_granted' => $lessonsGranted,
            // Capped at 100: a student can hold progress on a lesson whose grant
            // was since revoked, which would otherwise read as 120%.
            'content_completion_percent' => $lessonsGranted > 0
                ? min(100, (int) round($lessonsCompleted / $lessonsGranted * 100))
                : null,
            'attendance_present' => $attendance['present'],
            'attendance_total' => $attendance['total'],
            'attendance_percent' => $attendance['total'] > 0
                ? (int) round($attendance['present'] / $attendance['total'] * 100)
                : null,
            'exams_taken' => $exams['count'],
            'average_exam_percent' => $exams['count'] > 0
                ? (int) round($exams['sum'] / $exams['count'])
                : null,
            'last_active_at' => $this->lastActiveAt($tenantId, $userId)?->toIso8601String(),
            'devices_count' => PlaybackSession::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->whereNotNull('device_fingerprint')
                ->distinct()->count('device_fingerprint'),
        ];
    }

    /** @return array{present:int,total:int} */
    private function attendanceTotals(int $tenantId, int $userId): array
    {
        $byStatus = AttendanceRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'present' => (int) ($byStatus['present'] ?? 0),
            'total' => (int) $byStatus->sum(),
        ];
    }

    /**
     * Percent scores from both worlds — auto-graded online attempts and typed-in
     * paper (center) grades — averaged together, since the teacher thinks of them
     * as one "how is this student scoring" number.
     *
     * @return array{sum:float,count:int}
     */
    private function examTotals(int $tenantId, int $userId): array
    {
        $sum = 0.0;
        $count = 0;

        ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('status', AttemptStatus::Graded->value)
            ->where('max_score', '>', 0)
            ->get(['score', 'max_score'])
            ->each(function ($a) use (&$sum, &$count): void {
                $sum += (float) $a->score / (float) $a->max_score * 100;
                $count++;
            });

        CenterExamGrade::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('student_user_id', $userId)
            ->where('total_marks', '>', 0)
            ->get(['score', 'total_marks'])
            ->each(function ($g) use (&$sum, &$count): void {
                $sum += (float) $g->score / (float) $g->total_marks * 100;
                $count++;
            });

        return ['sum' => $sum, 'count' => $count];
    }

    /** The newest of: successful login, playback ticket, watched-lesson touch. */
    private function lastActiveAt(int $tenantId, int $userId): ?Carbon
    {
        $stamps = [
            LoginAttempt::query()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('success', true)->max('created_at'),
            PlaybackSession::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)->max('issued_at'),
            LessonProgress::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)->max('updated_at'),
        ];

        $stamps = array_values(array_filter($stamps));

        if ($stamps === []) {
            return null;
        }

        return collect($stamps)->map(fn ($s) => Carbon::parse($s))->max();
    }

    /**
     * Access as a single state the teacher can read at a glance. `expires_at` is
     * the FURTHEST active grant — the day the student actually loses everything —
     * and null there means open-ended access.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionState(int $tenantId, int $userId): array
    {
        $active = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['expires_at']);

        $anyEnrollment = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->exists();

        if ($active->isEmpty()) {
            return [
                'status' => $anyEnrollment ? 'expired' : 'none',
                'expires_at' => null,
                'active_count' => 0,
                'next_expiry_at' => null,
            ];
        }

        $openEnded = $active->contains(fn ($e) => $e->expires_at === null);
        $dated = $active->filter(fn ($e) => $e->expires_at !== null)->pluck('expires_at');

        return [
            'status' => 'active',
            'expires_at' => $openEnded ? null : $dated->max()?->toIso8601String(),
            'active_count' => $active->count(),
            // The soonest grant to lapse — what "expiring soon" should key on.
            'next_expiry_at' => $dated->min()?->toIso8601String(),
        ];
    }
}
