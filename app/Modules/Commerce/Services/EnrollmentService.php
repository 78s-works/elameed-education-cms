<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;

/**
 * Grants and checks course access. Takes an explicit tenant id so it works from
 * webhook contexts where no tenant is resolved from the host.
 */
class EnrollmentService
{
    public function grantCourse(int $tenantId, int $userId, Course $course, EnrollmentSource $source): Enrollment
    {
        $existing = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->first();

        if ($existing !== null) {
            return $existing; // already enrolled
        }

        $enrollment = new Enrollment([
            'user_id' => $userId,
            'course_id' => $course->getKey(),
            'source' => $source->value,
            'starts_at' => now(),
            'expires_at' => $course->access_days ? now()->addDays($course->access_days) : null,
            'status' => EnrollmentStatus::Active->value,
        ]);
        $enrollment->tenant_id = $tenantId;
        $enrollment->save();

        return $enrollment;
    }

    /** Does the user currently have access to the course? Free courses are open. */
    public function hasAccess(int $tenantId, int $userId, Course $course): bool
    {
        if ($course->is_free) {
            return true;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $course->getKey())
            ->grantsAccess()
            ->exists();
    }
}
