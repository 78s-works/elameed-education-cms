<?php

namespace App\Modules\PlatformAdmin\Services;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Elameed's own commercial position (ADM-19).
 *
 * The overview already reported the ACADEMIES' revenue from their students —
 * which is their money, not ours — and nothing at all about the subscription
 * business that actually pays for the platform. This answers the questions an
 * operator asks: what is recurring, who is trialling, who converts, who churned,
 * and who owes us money.
 *
 * Every figure is derived from `tenant_subscriptions`, so it cannot drift from
 * what the tenant pages show.
 */
class PlatformBusinessReport
{
    /** Trials this close to expiry are the ones worth calling today. */
    private const ENDING_SOON_DAYS = 7;

    /**
     * @param  int  $periodDays  window for the conversion rate
     * @return array<string, mixed>
     */
    public function build(int $periodDays = 30): array
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->subDays($periodDays);
        $monthStart = $now->startOfMonth();

        $subscriptions = TenantSubscription::query()
            ->with(['package:id,slug,name,price_minor,interval', 'tenant:id,uuid,name'])
            ->get();

        $live = $subscriptions->whereIn('status', [
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        ]);

        return [
            'mrr' => $this->mrr($live),
            'counts' => $this->counts($subscriptions),
            'conversion' => $this->conversion($subscriptions, $periodStart, $periodDays),
            'churn' => $this->churn($subscriptions, $monthStart),
            'trials_ending_soon' => $this->trialsEndingSoon($subscriptions, $now),
            'overdue' => $this->overdue($subscriptions),
            'currency' => $live->first()?->currency ?? 'EGP',
        ];
    }

    /**
     * Recurring revenue, normalised to a month. A yearly plan contributes a
     * twelfth of its price — otherwise a single annual sale would read as a
     * twelve-fold jump in monthly revenue.
     *
     * Trialling academies are excluded: they are not paying yet, and counting
     * them is how a platform talks itself into a revenue figure it does not have.
     *
     * @param  Collection<int, TenantSubscription>  $live
     * @return array<string, mixed>
     */
    private function mrr(Collection $live): array
    {
        $paying = $live->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue]);

        $byPlan = $paying
            ->groupBy(fn (TenantSubscription $s) => $s->package?->slug ?? 'unknown')
            ->map(fn (Collection $rows) => [
                'plan' => $rows->first()->package?->name ?? '—',
                'academies' => $rows->count(),
                'mrr_minor' => (int) $rows->sum(fn (TenantSubscription $s) => $this->monthlyValue($s)),
            ])
            ->values()
            ->all();

        return [
            'total_minor' => (int) $paying->sum(fn (TenantSubscription $s) => $this->monthlyValue($s)),
            'by_plan' => $byPlan,
        ];
    }

    private function monthlyValue(TenantSubscription $subscription): int
    {
        $price = (int) $subscription->price_minor;
        $interval = $subscription->package?->interval;
        $isYearly = $interval !== null && (string) (is_object($interval) ? $interval->value : $interval) === 'yearly';

        return $isYearly ? (int) round($price / 12) : $price;
    }

    /**
     * @param  Collection<int, TenantSubscription>  $subscriptions
     * @return array<string, int>
     */
    private function counts(Collection $subscriptions): array
    {
        $by = $subscriptions->groupBy(fn (TenantSubscription $s) => $s->status->value)->map->count();

        return [
            'trialing' => (int) ($by[SubscriptionStatus::Trialing->value] ?? 0),
            'active' => (int) ($by[SubscriptionStatus::Active->value] ?? 0),
            'past_due' => (int) ($by[SubscriptionStatus::PastDue->value] ?? 0),
            'canceled' => (int) ($by[SubscriptionStatus::Canceled->value] ?? 0),
        ];
    }

    /**
     * Trial-to-paid over the window: of the academies whose trial STARTED in the
     * period, how many are now paying. Measured on trial start, not on today's
     * status, so a long trial is not counted as a failure before it has ended.
     *
     * @param  Collection<int, TenantSubscription>  $subscriptions
     * @return array<string, mixed>
     */
    private function conversion(Collection $subscriptions, CarbonImmutable $periodStart, int $periodDays): array
    {
        $started = $subscriptions->filter(
            fn (TenantSubscription $s) => $s->trial_ends_at !== null
                && $s->started_at !== null
                && $s->started_at->greaterThanOrEqualTo($periodStart),
        );

        $converted = $started->filter(
            fn (TenantSubscription $s) => $s->status === SubscriptionStatus::Active
                || $s->status === SubscriptionStatus::PastDue,
        );

        return [
            'period_days' => $periodDays,
            'trials_started' => $started->count(),
            'converted' => $converted->count(),
            'rate' => $started->count() > 0
                ? round(($converted->count() / $started->count()) * 100, 1)
                : null,
        ];
    }

    /**
     * Cancellations this month against what was live at its start — the rate
     * only means something relative to the base it was lost from.
     *
     * @param  Collection<int, TenantSubscription>  $subscriptions
     * @return array<string, mixed>
     */
    private function churn(Collection $subscriptions, CarbonImmutable $monthStart): array
    {
        $canceled = $subscriptions->filter(
            fn (TenantSubscription $s) => $s->canceled_at !== null
                && $s->canceled_at->greaterThanOrEqualTo($monthStart),
        );

        $baseAtMonthStart = $subscriptions->filter(
            fn (TenantSubscription $s) => $s->started_at !== null
                && $s->started_at->lessThan($monthStart)
                && ($s->canceled_at === null || $s->canceled_at->greaterThanOrEqualTo($monthStart)),
        )->count();

        return [
            'canceled_this_month' => $canceled->count(),
            'base_at_month_start' => $baseAtMonthStart,
            'rate' => $baseAtMonthStart > 0
                ? round(($canceled->count() / $baseAtMonthStart) * 100, 1)
                : null,
        ];
    }

    /**
     * @param  Collection<int, TenantSubscription>  $subscriptions
     * @return list<array<string, mixed>>
     */
    private function trialsEndingSoon(Collection $subscriptions, CarbonImmutable $now): array
    {
        return $subscriptions
            ->filter(
                fn (TenantSubscription $s) => $s->status === SubscriptionStatus::Trialing
                    && $s->trial_ends_at !== null
                    && $s->trial_ends_at->between($now, $now->addDays(self::ENDING_SOON_DAYS)),
            )
            ->sortBy(fn (TenantSubscription $s) => $s->trial_ends_at)
            ->map(fn (TenantSubscription $s) => [
                'tenant_uuid' => $s->tenant?->uuid,
                'tenant_name' => $s->tenant?->name,
                'plan' => $s->package?->name,
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TenantSubscription>  $subscriptions
     * @return list<array<string, mixed>>
     */
    private function overdue(Collection $subscriptions): array
    {
        return $subscriptions
            ->where('status', SubscriptionStatus::PastDue)
            ->map(fn (TenantSubscription $s) => [
                'tenant_uuid' => $s->tenant?->uuid,
                'tenant_name' => $s->tenant?->name,
                'plan' => $s->package?->name,
                'price_minor' => (int) $s->price_minor,
                'renews_at' => $s->renews_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
