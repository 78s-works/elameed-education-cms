<?php

namespace App\Modules\Billing\Services;

use App\Support\Exceptions\DomainException;

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
            throw new DomainException(
                'plan_limit_reached',
                $this->message($key),
                403,
                ['key' => $key, 'limit' => $check['limit'], 'used' => $check['used']],
            );
        }
    }

    private function message(string $key): string
    {
        return match ($key) {
            'max_courses' => 'Your current plan does not allow adding more courses. Upgrade to add more.',
            'max_students' => 'Your current plan has reached its student limit. Upgrade to add more.',
            'max_assistants' => 'Your current plan has reached its assistant limit. Upgrade to add more.',
            'storage_mb' => 'Your current plan has reached its media-storage limit. Upgrade for more space.',
            default => 'Your current plan limit has been reached. Upgrade to continue.',
        };
    }
}
