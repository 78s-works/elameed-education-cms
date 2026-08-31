<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Services\SequentialUnlockService;

/**
 * The access terms of a purchasable item, as the student must be able to read
 * them BEFORE paying (they are a material term of sale, not a UX nicety).
 *
 * One builder shared by every surface that quotes a price — the checkout quote
 * lines and the public package tree — so "what we showed" can never drift from
 * "what we enforce". The numbers are the very columns the enforcement reads:
 *   • `availability_days`  — {@see LessonAvailabilityService::start}
 *                            stamps `expires_at = now + availability_days`; null/0
 *                            means the lesson never locks.
 *   • `extension_hours`    — the length of ONE extension, whether the student
 *                            self-reopens or staff grants it.
 *   • `self_reopen_limit`  — instant, no-staff extensions.
 *   • `max_extensions`     — the ceiling on the SHARED `extensions_used` counter.
 *
 * `starts_on` is the part clients get wrong, so it is stated explicitly rather
 * than implied:
 *   • a standalone lesson's window opens on FIRST OPEN (`POST /lessons/{id}/start`),
 *     never at payment — so no lock date exists until the student opens it;
 *   • a package opens only its FIRST lesson at purchase and each next lesson when
 *     the previous one is completed ({@see SequentialUnlockService}).
 */
final class AccessTerms
{
    /** A standalone lesson: its own window, opened by the student's first visit. */
    public static function forLesson(Lesson $lesson): array
    {
        $windowed = $lesson->hasAvailabilityWindow();

        return [
            'kind' => 'lesson',
            'windowed' => $windowed,
            // null on an unlimited lesson — the client must not print a duration.
            'days' => $windowed ? (int) $lesson->availability_days : null,
            'starts_on' => 'first_open',
            'extension_hours' => $windowed ? (int) $lesson->extension_hours : 0,
            'max_extensions' => $windowed ? (int) $lesson->max_extensions : 0,
            'self_reopen_limit' => $windowed ? (int) $lesson->self_reopen_limit : 0,
        ];
    }

    /**
     * A package: bought as a whole, but access is enforced per lesson and opened
     * in sequence, so the honest summary is the spread of its lessons' windows —
     * never a single package-wide countdown (there is no such thing server-side).
     */
    public static function forPackage(Package $package, PackageItemService $items): array
    {
        $lessons = Lesson::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $items->descendantLessonIds($package))
            ->get(['id', 'availability_days']);

        $days = $lessons
            ->filter(fn (Lesson $l) => $l->hasAvailabilityWindow())
            ->map(fn (Lesson $l) => (int) $l->availability_days)
            ->values();

        return [
            'kind' => 'package',
            // Every lesson carries its own window; they are opened one at a time.
            'sequential' => true,
            'lessons_count' => $lessons->count(),
            'windowed_lessons' => $days->count(),
            'unlimited_lessons' => $lessons->count() - $days->count(),
            'min_days' => $days->min(),
            'max_days' => $days->max(),
            // The first lesson's clock starts at payment; the rest on completion
            // of the lesson before them.
            'starts_on' => 'purchase_then_sequential',
        ];
    }
}
