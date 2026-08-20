<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Runtime part-visibility filter (B12 / LP-6): a student sees only the lesson
 * parts whose `access_mode` matches how they study (`study_mode`) — a `both`
 * (hybrid) student sees every channel, a `center` student sees center+both, an
 * `online` student sees online+both. The channel rule itself lives on
 * AccessMode::isVisibleTo(); this service resolves the student's study_mode and
 * applies it to a collection of parts.
 *
 * Access-critical, so tenant id is explicit and the profile lookup runs
 * withoutGlobalScopes (mirrors EnrollmentService / ContentUnlockService).
 */
class StudentPartVisibility
{
    /**
     * The student's study_mode as an AccessMode. Defaults to `both` (see-all) when
     * the profile is missing or has no study_mode — so students predating B5 are
     * never hidden from content they could previously reach.
     */
    public function studyModeFor(int $tenantId, int $userId): AccessMode
    {
        $mode = StudentProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->value('study_mode');

        return $mode !== null ? AccessMode::from($mode) : AccessMode::Both;
    }

    /**
     * The channel to filter ONE lesson's parts by.
     *
     * Normally the student's own study_mode (B12/LP-6). But a student can legitimately
     * hold a lesson on the OTHER channel — a teacher granting a center student an
     * online lesson, a redeemed code, a package fan-out. Their study_mode would then
     * hide every part of a lesson they own, leaving an empty player behind a lesson
     * that opens fine. When that happens the LESSON's own channel becomes the filter:
     * under the ceiling rule (AccessMode::isSubsetOf) a lesson's parts are only ever
     * that same channel or `both`, so nothing from the student's other channel can
     * leak in.
     *
     * A `both` (hybrid) lesson is unaffected — it is visible to every study_mode, so
     * the student's own channel still governs which of its parts they see.
     */
    public function studyModeForLesson(AccessMode $studyMode, Lesson $lesson, bool $granted): AccessMode
    {
        $lessonMode = $lesson->access_mode;

        if (! $granted || $lessonMode === null || $lessonMode->isVisibleTo($studyMode)) {
            return $studyMode;
        }

        return $lessonMode;
    }

    /**
     * Drop parts whose access_mode is not visible to $studyMode. Legacy parts with
     * a null access_mode carry no channel restriction and always show.
     *
     * @param  Collection<int, LessonSection>  $parts
     * @return Collection<int, LessonSection>
     */
    public function filter(Collection $parts, AccessMode $studyMode): Collection
    {
        return $parts
            ->filter(fn (LessonSection $part) => $part->access_mode === null
                || $part->access_mode->isVisibleTo($studyMode))
            ->values();
    }
}
