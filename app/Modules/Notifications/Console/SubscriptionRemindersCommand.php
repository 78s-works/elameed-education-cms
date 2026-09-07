<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use App\Modules\Notifications\Support\RunsInTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The two teacher-facing billing alerts that no request can raise, because they
 * are about the passage of time: a subscription about to lapse, and one that
 * already has.
 *
 * Run daily. Both notifications are idempotent per day: `notification_events`
 * is checked for a same-day event on the same subscription, so a second run (or
 * a retry) does not tell a teacher twice.
 */
class SubscriptionRemindersCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'notifications:subscription-reminders {--days=7 : How many days ahead counts as "expiring"}';

    protected $description = 'Notify teachers about subscriptions expiring soon or already expired';

    public function handle(NotificationEngineService $engine): int
    {
        $days = max(1, (int) $this->option('days'));
        $expiring = $this->notifyExpiring($engine, $days);
        $expired = $this->notifyExpired($engine);

        $this->info(sprintf('Subscription reminders — expiring: %d, expired: %d.', $expiring, $expired));

        return self::SUCCESS;
    }

    private function notifyExpiring(NotificationEngineService $engine, int $days): int
    {
        $rows = DB::table('tenant_subscriptions')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays($days)])
            ->get();

        return $this->fanOut($engine, $rows, 'billing.subscription.expiring');
    }

    private function notifyExpired(NotificationEngineService $engine): int
    {
        $rows = DB::table('tenant_subscriptions')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->whereIn('status', ['active', 'trialing', 'past_due', 'expired'])
            ->get();

        return $this->fanOut($engine, $rows, 'billing.subscription.expired');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function fanOut(NotificationEngineService $engine, $rows, string $key): int
    {
        $sent = 0;

        foreach ($rows as $subscription) {
            $tenantId = (int) $subscription->tenant_id;

            if ($this->alreadyNotifiedToday($key, $subscription->id)) {
                continue;
            }

            $owners = DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->where('role', 'teacher')
                ->where('status', 'active')
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($owners === []) {
                continue;
            }

            $this->inTenantContext($tenantId, function () use ($engine, $key, $tenantId, $owners, $subscription): void {
                $engine->dispatch(
                    notificationKey: $key,
                    tenantId: $tenantId,
                    recipientUserIds: $owners,
                    renderVariables: [
                        'expires_at' => (string) $subscription->ends_at,
                    ],
                    entityType: 'tenant_subscription',
                    entityId: (int) $subscription->id,
                    auditPayload: ['ends_at' => (string) $subscription->ends_at],
                );
            });

            $sent++;
        }

        return $sent;
    }

    /** Has this exact reminder already fired for this subscription today? */
    private function alreadyNotifiedToday(string $key, int $subscriptionId): bool
    {
        return DB::table('notification_events')
            ->join('notification_types', 'notification_types.id', '=', 'notification_events.notification_type_id')
            ->where('notification_types.key', $key)
            ->where('notification_events.entity_type', 'tenant_subscription')
            ->where('notification_events.entity_id', $subscriptionId)
            ->whereDate('notification_events.created_at', now()->toDateString())
            ->exists();
    }
}
