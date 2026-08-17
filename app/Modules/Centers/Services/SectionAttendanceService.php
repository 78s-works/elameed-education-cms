<?php

namespace App\Modules\Centers\Services;

use App\Models\User;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;

/**
 * Center check-in → time-boxed online access. A teacher marks a center student
 * present for a lesson part; that opens the part's parent lesson online for the
 * student for the lesson's `availability_days` (the same window students get on a
 * normal purchase). The attendance row logs the check-in and snapshots when the
 * granted access ends.
 *
 * Granularity note: access is granted at the LESSON level (the only entitlement
 * gate the student panel enforces); the student still sees only the center/both
 * parts of that lesson, filtered by their study_mode.
 */
class SectionAttendanceService
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly LessonAvailabilityService $availability,
    ) {}

    /**
     * Mark $student present for $section at $center and (re)open the section's
     * parent lesson online. Idempotent per (center, student, day, section): a
     * repeat check-in on the same day refreshes the window from now. Returns the
     * attendance log row.
     */
    public function checkin(int $tenantId, Center $center, LessonSection $section, User $student, int $markedBy): AttendanceRecord
    {
        $lesson = $section->lesson;

        // Entitlement (idempotent) — passes the lesson-level access gate.
        $this->enrollments->grantLesson($tenantId, $student->getKey(), $lesson, EnrollmentSource::Center);

        // Time-box: (re)open the playback window for availability_days from now,
        // clearing any prior lock/expiry. Unlimited lessons carry no window.
        $expiresAt = null;
        if ($lesson->hasAvailabilityWindow()) {
            $window = $this->availability->reopen($tenantId, $student->getKey(), $lesson, (int) $lesson->availability_days * 24);
            $expiresAt = $window->expires_at;
        }

        return AttendanceRecord::updateOrCreate(
            [
                'center_id' => $center->getKey(),
                'user_id' => $student->getKey(),
                'attended_on' => now()->toDateString(),
                'lesson_section_id' => $section->getKey(),
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
     * Revoke a section check-in: lock the student's window now and cancel the
     * lesson grant, then stamp the attendance row's access as ended. Leaves the
     * attendance log row itself in place (history).
     */
    public function revoke(int $tenantId, AttendanceRecord $record): void
    {
        $section = $record->lessonSection;
        $lesson = $section?->lesson;

        if ($lesson !== null) {
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

        $record->access_expires_at = now();
        $record->save();
    }
}
