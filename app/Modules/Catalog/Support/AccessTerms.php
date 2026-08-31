<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Services\SequentialUnlockService;
use App\Modules\Commerce\Services\FulfillOrderService;

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
 * than implied. Both values below are what the code actually does:
 *   • a standalone lesson's window opens AT PAYMENT, not on first open:
 *     {@see FulfillOrderService} calls
 *     EnrollmentService::grantLesson, which calls LessonAvailabilityService::start
 *     right there — "so the 'week' counts from the grant/payment (decision D3)".
 *     `POST /lessons/{id}/start` is idempotent, so for a purchased lesson it can
 *     only ever return the window payment already opened. The same holds for the
 *     other grant paths (code redemption, center attendance).
 *   • a package opens only its FIRST lesson at purchase and each next lesson when
 *     the previous one is completed ({@see SequentialUnlockService}); the fan-out
 *     itself grants access without opening windows (EnrollmentService::grantPackageLesson).
 */
final class AccessTerms
{
    /** A standalone lesson: its own window, opened the moment the purchase lands. */
    public static function forLesson(Lesson $lesson): array
    {
        $windowed = $lesson->hasAvailabilityWindow();

        return [
            'kind' => 'lesson',
            'windowed' => $windowed,
            // null on an unlimited lesson — the client must not print a duration.
            'days' => $windowed ? (int) $lesson->availability_days : null,
            // At PAYMENT. Buying the lesson opens the window immediately, so the
            // clock is already running whether or not the student opens it.
            'starts_on' => 'purchase',
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
