<?php

namespace App\Modules\Centers\Services;

use App\Models\User;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterSession;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;

/**
 * Center check-in → time-boxed online access. A teacher marks a center student
 * present for a session; that opens every lesson the session bundles online for
 * the student for each lesson's `availability_days` (the same window a purchase
 * gives). The attendance row logs the check-in and snapshots the furthest access
 * expiry across the session's lessons.
 */
class CenterSessionAttendanceService
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly LessonAvailabilityService $availability,
    ) {}

    /**
     * Mark $student present for $session at $center and (re)open all of the
     * session's lessons online. Idempotent per (center, student, day, session): a
     * repeat check-in on the same day refreshes the windows from now. Returns the
     * attendance log row.
     */
    public function checkin(int $tenantId, Center $center, CenterSession $session, User $student, int $markedBy): AttendanceRecord
    {
        $expiresAt = null;

        foreach ($session->lessons as $lesson) {
            // Entitlement (idempotent) — passes the lesson-level access gate.
            $this->enrollments->grantLesson($tenantId, $student->getKey(), $lesson, EnrollmentSource::Center);

            // Time-box: (re)open each window for availability_days from now. Track
            // the furthest expiry for the record snapshot; unlimited lessons carry
            // no window and leave the snapshot null.
            if ($lesson->hasAvailabilityWindow()) {
                $window = $this->availability->reopen($tenantId, $student->getKey(), $lesson, (int) $lesson->availability_days * 24);
                if ($expiresAt === null || $window->expires_at->greaterThan($expiresAt)) {
                    $expiresAt = $window->expires_at;
                }
            }
        }

        return AttendanceRecord::updateOrCreate(
            [
                'center_id' => $center->getKey(),
                'user_id' => $student->getKey(),
                'attended_on' => now()->toDateString(),
                'center_session_id' => $session->getKey(),
            ],
            [
                'access_expires_at' => $expiresAt,
                'status' => 'present',
                'marked_by' => $markedBy,
                'source' => 'center',
            ],
        );
    }

    /**
     * Revoke a session check-in: lock the student's window for each of the
     * session's lessons and cancel those center grants, then stamp the attendance
     * row's access as ended. Leaves the log row in place (history).
     */
    public function revoke(int $tenantId, AttendanceRecord $record): void
    {
        $session = $record->centerSession;

        if ($session !== null) {
            foreach ($session->lessons as $lesson) {
                $window = $this->availability->windowFor($tenantId, $record->user_id, $lesson);
                if ($window !== null && ! $window->isLocked()) {
                    $window->locked_at = now();
                    $window->save();
                }

                Enrollment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $record->user_id)
                    ->where('lesson_id', $lesson->getKey())
                    ->where('source', EnrollmentSource::Center->value)
                    ->where('status', EnrollmentStatus::Active->value)
                    ->update(['status' => EnrollmentStatus::Cancelled->value]);
            }
        }

        $record->access_expires_at = now();
        $record->save();
    }
}
