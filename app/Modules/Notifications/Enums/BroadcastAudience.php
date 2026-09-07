<?php

namespace App\Modules\Notifications\Enums;

/**
 * Who a custom (human-written) notification goes to. The sender picks one of
 * these; `AudienceResolver` turns it plus `audience_ids` into user ids.
 *
 * Everything except `Teachers` is academy-scoped and resolved inside the
 * sender's own tenant, so a teacher can never address another academy's people.
 * `Teachers` is the platform-admin audience (admin → all teachers) and is
 * rejected on the teacher surface.
 */
enum BroadcastAudience: string
{
    /** Every active student of the academy. */
    case AllStudents = 'all_students';
    /** Students enrolled in one lesson. `audience_ids` = [lesson id]. */
    case Lesson = 'lesson';
    /** Students holding one package. `audience_ids` = [package id]. */
    case Package = 'package';
    /** Students in one academic year / grade. `audience_ids` = [academic_year id]. */
    case AcademicYear = 'academic_year';
    /** Students attached to one center. `audience_ids` = [center id]. */
    case Center = 'center';
    /** Hand-picked students. `audience_ids` = [user id, ...]. */
    case Students = 'students';
    /** Every active assistant of the academy. */
    case Assistants = 'assistants';
    /** Every academy owner on the platform — central admin only. */
    case Teachers = 'teachers';

    /** Audiences a teacher/assistant may address from the academy surface. */
    public function isTenantScoped(): bool
    {
        return $this !== self::Teachers;
    }

    /** Does this audience need `audience_ids` to mean anything? */
    public function requiresIds(): bool
    {
        return match ($this) {
            self::Lesson, self::Package, self::AcademicYear, self::Center, self::Students => true,
            default => false,
        };
    }
}
