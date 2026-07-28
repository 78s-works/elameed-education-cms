<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ExtensionStatus;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\LessonExtensionRequest;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Time-boxed lesson access ("Lesson Availability & Extension Requests" + the
 * "Lesson Countdown Timer" that reads it). A lesson with `availability_days`
 * gives each student a window that opens on first start and auto-locks at
 * expiry; students may request up to `max_extensions` extensions.
 *
 * Access-critical: explicit tenant id, queries withoutGlobalScopes.
 */
class LessonAvailabilityService
{
    /**
     * Open the student's window (or return the running one). Idempotent per
     * (user, lesson). Returns null when the lesson is unlimited (no window).
     */
    public function start(int $tenantId, int $userId, Lesson $lesson): ?LessonAccessWindow
    {
        if (! $lesson->hasAvailabilityWindow()) {
            return null;
        }

        $existing = $this->windowFor($tenantId, $userId, $lesson);
        if ($existing !== null) {
            return $existing;
        }

        $window = new LessonAccessWindow([
            'user_id' => $userId,
            'lesson_id' => $lesson->getKey(),
            'started_at' => now(),
            'expires_at' => now()->addDays((int) $lesson->availability_days),
            'extensions_used' => 0,
        ]);
        $window->tenant_id = $tenantId;
        $window->save();

        return $window;
    }

    public function windowFor(int $tenantId, int $userId, Lesson $lesson): ?LessonAccessWindow
    {
        return LessonAccessWindow::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $lesson->getKey())
            ->first();
    }

    /**
     * Student requests more time. Requires a started window, extensions enabled,
     * and remaining allowance; only one pending request at a time.
     */
    public function requestExtension(int $tenantId, int $userId, Lesson $lesson): LessonExtensionRequest
    {
        $window = $this->windowFor($tenantId, $userId, $lesson);
        if ($window === null) {
            throw new ConflictHttpException('Start the lesson before requesting an extension.');
        }

        if ((int) $lesson->max_extensions <= 0) {
            throw new ConflictHttpException('Extensions are not allowed for this lesson.');
        }

        if ($window->extensions_used >= (int) $lesson->max_extensions) {
            throw new ConflictHttpException('No extension requests remain for this lesson.');
        }

        $hasPending = LessonExtensionRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('access_window_id', $window->getKey())
            ->where('status', ExtensionStatus::Pending->value)
            ->exists();

        if ($hasPending) {
            throw new ConflictHttpException('An extension request is already pending.');
        }

        $request = new LessonExtensionRequest([
            'access_window_id' => $window->getKey(),
            'user_id' => $userId,
            'status' => ExtensionStatus::Pending->value,
            'requested_at' => now(),
        ]);
        $request->tenant_id = $tenantId;
        $request->save();

        return $request;
    }

    /**
     * Staff grant/deny. A grant extends the window by the lesson's
     * `extension_hours` (from now if already expired, else from current expiry),
     * clears the lock, and consumes one extension.
     */
    public function decide(int $tenantId, LessonExtensionRequest $request, bool $grant, int $staffId): LessonExtensionRequest
    {
        if ($request->status !== ExtensionStatus::Pending) {
            throw new ConflictHttpException('This request has already been decided.');
        }

        if ($grant) {
            $window = LessonAccessWindow::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->findOrFail($request->access_window_id);

            $lesson = Lesson::withoutGlobalScopes()->find($window->lesson_id);
            $hours = (int) ($lesson?->extension_hours ?? 24);

            $base = $window->expires_at->isPast() ? now() : $window->expires_at;
            $window->expires_at = $base->copy()->addHours($hours);
            $window->locked_at = null;
            $window->extensions_used = $window->extensions_used + 1;
            $window->save();

            $request->status = ExtensionStatus::Granted;
        } else {
            $request->status = ExtensionStatus::Denied;
        }

        $request->decided_at = now();
        $request->decided_by = $staffId;
        $request->save();

        return $request;
    }
}
