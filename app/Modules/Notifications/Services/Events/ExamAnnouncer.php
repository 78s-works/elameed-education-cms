<?php

namespace App\Modules\Notifications\Services\Events;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Services\Broadcasts\AudienceResolver;
use App\Modules\Notifications\Services\Engine\NotificationEngineService;

/**
 * "A new exam is published" — fired when `is_published` flips false → true.
 *
 * Audience depends on what the exam is attached to: a lesson-linked exam
 * (homework, lesson quiz) concerns the students who hold that lesson, while a
 * standalone exam concerns the whole grade. Re-publishing an exam the teacher
 * had unpublished announces it again on purpose: that is a deliberate act by the
 * teacher, not an accident to be de-duplicated away.
 */
class ExamAnnouncer
{
    public const KEY = 'exams.exam.published';

    public function __construct(
        private readonly NotificationEngineService $engine,
        private readonly AudienceResolver $audiences,
    ) {}

    public function announce(Exam $exam, ?int $actorId = null): bool
    {
        $tenantId = (int) $exam->tenant_id;
        $recipients = $this->recipientsFor($exam, $tenantId);

        if ($recipients === []) {
            return false;
        }

        $this->engine->dispatch(
            notificationKey: self::KEY,
            tenantId: $tenantId,
            recipientUserIds: $recipients,
            renderVariables: ['exam.title' => (string) $exam->title],
            triggeredByUserId: $actorId,
            entityType: 'exam',
            entityId: $exam->getKey(),
            auditPayload: ['exam_uuid' => $exam->uuid, 'type' => (string) ($exam->type->value ?? '')],
        );

        return true;
    }

    /** @return list<int> */
    private function recipientsFor(Exam $exam, int $tenantId): array
    {
        if ($exam->lesson_id !== null) {
            return $this->audiences->resolve(
                BroadcastAudience::Lesson,
                $tenantId,
                [(int) $exam->lesson_id],
            );
        }

        if ($exam->academic_year_id !== null) {
            return $this->audiences->resolve(
                BroadcastAudience::AcademicYear,
                $tenantId,
                [(int) $exam->academic_year_id],
            );
        }

        return $this->audiences->resolve(BroadcastAudience::AllStudents, $tenantId);
    }
}
