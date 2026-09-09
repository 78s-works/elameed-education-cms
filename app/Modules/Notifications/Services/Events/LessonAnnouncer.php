<?php

namespace App\Modules\Notifications\Services\Events;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Services\Broadcasts\AudienceResolver;
use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use Illuminate\Support\Facades\DB;

/**
 * "A new lesson is available" — the one automatic notification whose trigger is
 * not a single line in a controller.
 *
 * A lesson becomes available in three different ways: it is created visible, an
 * existing hidden one is switched to visible, or a `scheduled` one reaches its
 * `publish_at`. All three land here, and the announcement is sent AT MOST ONCE
 * per lesson: `notification_events` is the idempotency record, so re-saving a
 * lesson, or the scheduler running again, does not re-announce it.
 *
 * The audience is the lesson's grade (academic year) rather than only students
 * already enrolled in it — an announcement whose point is "there is new content"
 * would be useless if it only reached people who already own it.
 */
class LessonAnnouncer
{
    public const KEY = 'lessons.lesson.available';

    public function __construct(
        private readonly NotificationEngineService $engine,
        private readonly AudienceResolver $audiences,
    ) {}

    /** True when this lesson is publicly available right now. */
    public function isAvailable(Lesson $lesson): bool
    {
        if ($lesson->visibility !== ContentVisibility::Visible) {
            return false;
        }

        return $lesson->publish_at === null || $lesson->publish_at->isPast();
    }

    /**
     * Announce the lesson if it is available and has not been announced before.
     *
     * @return bool whether an announcement was actually sent
     */
    public function announce(Lesson $lesson, ?int $actorId = null): bool
    {
        if (! $this->isAvailable($lesson) || $this->alreadyAnnounced($lesson)) {
            return false;
        }

        $tenantId = (int) $lesson->tenant_id;

        $recipients = $lesson->academic_year_id === null
            ? $this->audiences->resolve(BroadcastAudience::AllStudents, $tenantId)
            : $this->audiences->resolve(
                BroadcastAudience::AcademicYear,
                $tenantId,
                [(int) $lesson->academic_year_id],
            );

        if ($recipients === []) {
            return false;
        }

        $this->engine->dispatch(
            notificationKey: self::KEY,
            tenantId: $tenantId,
            recipientUserIds: $recipients,
            renderVariables: ['lesson.title' => (string) $lesson->title],
            triggeredByUserId: $actorId,
            entityType: 'lesson',
            entityId: $lesson->getKey(),
            auditPayload: ['lesson_id' => $lesson->getKey()],
        );

        return true;
    }

    private function alreadyAnnounced(Lesson $lesson): bool
    {
        return DB::table('notification_events')
            ->join('notification_types', 'notification_types.id', '=', 'notification_events.notification_type_id')
            ->where('notification_types.key', self::KEY)
            ->where('notification_events.entity_type', 'lesson')
            ->where('notification_events.entity_id', $lesson->getKey())
            ->exists();
    }
}
