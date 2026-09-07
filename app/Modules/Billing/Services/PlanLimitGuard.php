<?php

namespace App\Modules\Billing\Services;

use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use App\Modules\Notifications\Support\StaffRecipients;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces a tenant's subscription-package limits (FR-M03-02) at creation time:
 * courses, students, assistants, and media storage. Reads the tenant's current
 * package via SubscriptionService and the live usage via PackageUsage.
 *
 * Rules:
 *  - A null limit (or no active subscription / package) = unlimited → never blocks.
 *    Tenants without an assigned plan are unrestricted, matching the P1 behaviour
 *    where billing packages are optional.
 *  - `ensure()` throws a `plan_limit_reached` DomainException (403) when creating
 *    `$additional` more would exceed the limit, so the SPA can surface an upgrade
 *    prompt.
 */
class PlanLimitGuard
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PackageUsage $usage,
        private readonly NotificationEngineService $engine,
    ) {}

    /**
     * @return array{limit: int|null, used: int, remaining: int|null, allowed: bool}
     */
    public function check(int $tenantId, string $key, int $additional = 1): array
    {
        $limit = $this->subscriptions->current($tenantId)?->package?->limit($key);
        $used = $this->usage->usedFor($tenantId, $key);

        if ($limit === null) {
            return ['limit' => null, 'used' => $used, 'remaining' => null, 'allowed' => true];
        }

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'allowed' => ($used + $additional) <= $limit,
        ];
    }

    /**
     * Assert the tenant may create `$additional` more of `$key`, or throw.
     *
     * @throws DomainException
     */
    public function ensure(int $tenantId, string $key, int $additional = 1): void
    {
        $check = $this->check($tenantId, $key, $additional);

        if (! $check['allowed']) {
            $this->notifyOwners($tenantId, $key, $check);

            throw new DomainException(
                'plan_limit_reached',
                $this->message($key),
                403,
                ['key' => $key, 'limit' => $check['limit'], 'used' => $check['used']],
            );
        }
    }

    /**
     * Tell the academy owner the plan is full — once per limit per day. The
     * teacher hits the wall in the UI anyway; the notification exists so the
     * person who pays for the plan hears about it even when it was an assistant
     * who ran into it, and it is rate-limited because a blocked import can throw
     * this exception hundreds of times in a row.
     *
     * @param  array{limit: int|null, used: int, remaining: int|null, allowed: bool}  $check
     */
    private function notifyOwners(int $tenantId, string $key, array $check): void
    {
        if ($this->alreadyNotifiedToday($tenantId, $key)) {
            return;
        }

        $owners = StaffRecipients::owners($tenantId);

        if ($owners === []) {
            return;
        }

        $this->engine->dispatch(
            notificationKey: 'billing.plan_limit.reached',
            tenantId: $tenantId,
            recipientUserIds: $owners,
            renderVariables: ['limit' => $this->limitLabel($key, $this->tenantLocale($tenantId))],
            entityType: 'plan_limit',
            entityId: null,
            auditPayload: ['key' => $key, 'limit' => $check['limit'], 'used' => $check['used']],
        );
    }

    private function alreadyNotifiedToday(int $tenantId, string $key): bool
    {
        return DB::table('notification_events')
            ->join('notification_types', 'notification_types.id', '=', 'notification_events.notification_type_id')
            ->where('notification_types.key', 'billing.plan_limit.reached')
            ->where('notification_events.tenant_id', $tenantId)
            ->where('notification_events.entity_type', 'plan_limit')
            ->whereDate('notification_events.created_at', now()->toDateString())
            ->where('notification_events.payload->key', $key)
            ->exists();
    }

    /**
     * The quota's name as it should read INSIDE the notification copy.
     *
     * Localized by the academy's own locale rather than left in English: the
     * alert goes to that academy's owner(s), and "بلغت حد students" is not a
     * sentence. Render variables are shared across the whole dispatch, so the
     * tenant locale — not a per-recipient one — is the right basis here.
     */
    private function limitLabel(string $key, string $locale): string
    {
        $labels = [
            'max_lessons' => ['ar' => 'الدروس', 'en' => 'lessons'],
            'max_students' => ['ar' => 'الطلاب', 'en' => 'students'],
            'max_assistants' => ['ar' => 'المساعدين', 'en' => 'assistants'],
            'storage_mb' => ['ar' => 'المساحة', 'en' => 'storage'],
        ];

        return $labels[$key][$locale] ?? $labels[$key]['en'] ?? $key;
    }

    /** The academy's display language, defaulting to the platform's. */
    private function tenantLocale(int $tenantId): string
    {
        $locale = Tenant::query()
            ->with('teacherProfile')
            ->find($tenantId)
            ?->teacherProfile
            ?->primary_locale;

        return $locale ?: (string) config('tenancy.default_locale', 'ar');
    }

    private function message(string $key): string
    {
        return match ($key) {
            'max_lessons' => 'Your current plan does not allow adding more lessons. Upgrade to add more.',
            'max_students' => 'Your current plan has reached its student limit. Upgrade to add more.',
            'max_assistants' => 'Your current plan has reached its assistant limit. Upgrade to add more.',
            'storage_mb' => 'Your current plan has reached its media-storage limit. Upgrade for more space.',
            default => 'Your current plan limit has been reached. Upgrade to continue.',
        };
    }
}
