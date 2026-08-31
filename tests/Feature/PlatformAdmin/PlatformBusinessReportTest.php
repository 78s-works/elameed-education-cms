<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The platform's own subscription business (ADM-19). The figure that matters
 * most here is MRR, and the two ways to get it wrong are counting trials as
 * revenue and counting an annual plan as a monthly one — both are asserted.
 */
class PlatformBusinessReportTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $slug, int $priceMinor, string $interval): SubscriptionPackage
    {
        return SubscriptionPackage::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'price_minor' => $priceMinor,
            'interval' => $interval, 'trial_days' => 14, 'limits' => [],
        ]);
    }

    private function subscribe(string $slug, SubscriptionPackage $plan, SubscriptionStatus $status, array $attrs = []): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);

        TenantSubscription::create(array_merge([
            'tenant_id' => $tenant->id,
            'package_id' => $plan->id,
            'status' => $status->value,
            'price_minor' => $plan->price_minor,
            'currency' => 'EGP',
            'started_at' => now()->subDays(10),
        ], $attrs));

        return $tenant;
    }

    public function test_mrr_excludes_trials_and_normalises_yearly_plans(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $monthly = $this->plan('starter', 49900, 'monthly');
        $yearly = $this->plan('pro', 1200000, 'yearly');

        $this->subscribe('a', $monthly, SubscriptionStatus::Active);
        $this->subscribe('b', $yearly, SubscriptionStatus::Active);
        // Trialling: not revenue, however promising.
        $this->subscribe('c', $monthly, SubscriptionStatus::Trialing, [
            'trial_ends_at' => now()->addDays(3),
        ]);

        $response = $this->getJson('/api/v1/admin/reports/platform-business')->assertOk();

        // 499.00 + (12,000.00 / 12) = 1,499.00
        $this->assertSame(49900 + 100000, $response->json('data.mrr.total_minor'));
        $this->assertSame(1, $response->json('data.counts.trialing'));
        $this->assertSame(2, $response->json('data.counts.active'));
        $this->assertCount(2, $response->json('data.mrr.by_plan'));
    }

    public function test_trials_ending_soon_and_overdue_are_listed_by_name(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $plan = $this->plan('starter', 49900, 'monthly');

        $this->subscribe('soon', $plan, SubscriptionStatus::Trialing, [
            'trial_ends_at' => now()->addDays(2),
        ]);
        // Beyond the 7-day window — not today's problem.
        $this->subscribe('later', $plan, SubscriptionStatus::Trialing, [
            'trial_ends_at' => now()->addDays(20),
        ]);
        $this->subscribe('late', $plan, SubscriptionStatus::PastDue);

        $response = $this->getJson('/api/v1/admin/reports/platform-business')->assertOk();

        $this->assertCount(1, $response->json('data.trials_ending_soon'));
        $this->assertSame('Soon', $response->json('data.trials_ending_soon.0.tenant_name'));
        $this->assertCount(1, $response->json('data.overdue'));
        $this->assertSame('Late', $response->json('data.overdue.0.tenant_name'));
    }

    public function test_tenant_list_can_be_filtered_by_subscription_state(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $plan = $this->plan('starter', 49900, 'monthly');

        $this->subscribe('paying', $plan, SubscriptionStatus::Active);
        $this->subscribe('late', $plan, SubscriptionStatus::PastDue);

        $this->getJson('/api/v1/admin/tenants?subscription=past_due')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'late');
    }

    public function test_report_is_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/platform-business')->assertStatus(403);
    }
}
