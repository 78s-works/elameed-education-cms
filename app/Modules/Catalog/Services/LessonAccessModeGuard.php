<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the access_mode ceiling (VD change set §7, LP-5): a part's channel
 * must be a subset of its lesson's channel — `both ⊇ {both,online,center}`,
 * `online ⊇ {online}`, `center ⊇ {center}`. Two entry points:
 *
 *   - assertPartWithinLesson() — at part create/update.
 *   - assertLessonNarrowingAllowed() — when a lesson narrows its own mode, every
 *     existing part must still fit; otherwise a 422 lists the offending parts.
 */
class LessonAccessModeGuard
{
    /**
     * The part's access_mode must be ⊆ the lesson's. Throws a 422 keyed on
     * `access_mode` otherwise.
     */
    public function assertPartWithinLesson(AccessMode $part, Lesson $lesson): void
    {
        if (! $part->isSubsetOf($lesson->access_mode)) {
            throw ValidationException::withMessages([
                'access_mode' => sprintf(
                    "A part's access_mode (%s) must be within the lesson's access_mode (%s).",
                    $part->value,
                    $lesson->access_mode->value,
                ),
            ]);
        }
    }

    /**
     * Existing parts whose access_mode would no longer fit if the lesson adopted
     * $newMode. Parts with no access_mode (legacy sections) are exempt.
     *
     * @return Collection<int, LessonSection>
     */
    public function offendingParts(Lesson $lesson, AccessMode $newMode): Collection
    {
        return $lesson->sections()
            ->whereNotNull('access_mode')
            ->get()
            ->reject(fn (LessonSection $part) => $part->access_mode->isSubsetOf($newMode))
            ->values();
    }

    /**
     * Guard a lesson narrowing its access_mode: if any existing part would fall
     * outside $newMode, reject with a 422 that names the offending parts.
     */
    public function assertLessonNarrowingAllowed(Lesson $lesson, AccessMode $newMode): void
    {
        $offending = $this->offendingParts($lesson, $newMode);

        if ($offending->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'access_mode' => [sprintf(
                'Cannot narrow the lesson to %s: %d part(s) have a wider access_mode.',
                $newMode->value,
                $offending->count(),
            )],
            'offending_parts' => $offending
                ->map(fn (LessonSection $p) => ['id' => $p->id, 'title' => $p->title, 'access_mode' => $p->access_mode->value])
                ->all(),
        ]);
    }
}
