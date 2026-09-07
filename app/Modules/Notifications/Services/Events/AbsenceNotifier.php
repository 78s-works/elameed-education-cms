<?php

namespace App\Modules\Notifications\Services\Events;

use App\Models\User;
use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use App\Modules\Notifications\Services\Engine\TemplatedSmsNotifier;
use Illuminate\Support\Facades\DB;

/**
 * "Marked absent" — the one notification with two audiences: the student, and
 * the parent.
 *
 * A parent reaches this in whichever way the academy actually recorded them:
 *   - linked as a parent USER (`parent_links`) → a full recipient, so they get
 *     the in-app copy and any channel they have, exactly like the student, and
 *   - recorded only as a `guardian_phone` on the student profile → texted
 *     through TemplatedSmsNotifier, since a phone number owns no user row.
 *
 * A guardian recorded both ways is only texted once: the phone is skipped when
 * a linked parent user already carries it.
 */
class AbsenceNotifier
{
    public const KEY = 'center.attendance.absent';

    public function __construct(
        private readonly NotificationEngineService $engine,
        private readonly TemplatedSmsNotifier $sms,
    ) {}

    /**
     * @param  array<string, mixed>  $variables  extra copy variables (date, session)
     */
    public function notify(int $tenantId, User $student, array $variables = [], ?int $actorId = null): void
    {
        $parentIds = $this->linkedParentIds($tenantId, (int) $student->getKey());

        $vars = array_merge([
            'student.name' => (string) $student->name,
            'date' => now()->toDateString(),
        ], $variables);

        $this->engine->dispatch(
            notificationKey: self::KEY,
            tenantId: $tenantId,
            recipientUserIds: array_merge([(int) $student->getKey()], $parentIds),
            renderVariables: $vars,
            triggeredByUserId: $actorId,
            entityType: 'user',
            entityId: $student->getKey(),
            auditPayload: ['parents_notified' => count($parentIds)],
        );

        $phones = $this->guardianPhones($tenantId, (int) $student->getKey(), $parentIds);

        if ($phones !== []) {
            $this->sms->send(self::KEY, $tenantId, $phones, $vars);
        }
    }

    /** @return list<int> */
    private function linkedParentIds(int $tenantId, int $studentId): array
    {
        return DB::table('parent_links')
            ->where('tenant_id', $tenantId)
            ->where('student_user_id', $studentId)
            ->pluck('parent_user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The guardian phone on the student's profile, unless a linked parent user
     * already owns that number (they were notified as a recipient).
     *
     * @param  list<int>  $parentIds
     * @return list<string>
     */
    private function guardianPhones(int $tenantId, int $studentId, array $parentIds): array
    {
        $phone = DB::table('student_profiles')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $studentId)
            ->value('guardian_phone');

        $phone = trim((string) $phone);

        if ($phone === '') {
            return [];
        }

        if ($parentIds !== []) {
            $covered = User::query()
                ->whereIn('id', $parentIds)
                ->pluck('phone')
                ->map(static fn ($p): string => trim((string) $p))
                ->filter()
                ->all();

            if (in_array($phone, $covered, true)) {
                return [];
            }
        }

        return [$phone];
    }
}
