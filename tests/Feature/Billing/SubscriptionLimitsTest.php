<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\PlanLimitGuard;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creation-time enforcement of subscription-package limits (FR-M03-02) via
 * PlanLimitGuard: courses and students are blocked once the plan's quota is
 * exhausted; a tenant with no plan (or an unlimited limit) is never blocked.
 */
class SubscriptionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function assignPlan(array $limits): void
    {
        $package = SubscriptionPackage::create([
            'slug' => 'limited', 'name' => 'Limited', 'price_minor' => 0,
            'interval' => 'monthly', 'trial_days' => 0, 'limits' => $limits,
        ]);
        app(SubscriptionService::class)->assign($this->tenant, $package);
    }

    public function test_course_creation_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $this->assignPlan(['max_courses' => 1]);
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson('/api/v1/teacher/courses', ['title' => 'First'])
            ->assertStatus(201);

        $this->withHeaders($h)->postJson('/api/v1/teacher/courses', ['title' => 'Second'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details.key', 'max_courses');

        // Only the first course was actually persisted.
        $this->assertSame(1, Course::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_student_creation_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $this->assignPlan(['max_students' => 1]);
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson('/api/v1/teacher/students', ['name' => 'Ali', 'phone' => '01200000001'])
            ->assertStatus(201);

        $this->withHeaders($h)->postJson('/api/v1/teacher/students', ['name' => 'Sara', 'phone' => '01200000002'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details.key', 'max_students');

        $this->assertSame(1, TenantUser::query()->where('tenant_id', $this->tenant->id)
            ->where('role', TenantUserRole::Student->value)->count());
    }

    public function test_no_plan_means_unlimited(): void
    {
        // No subscription assigned at all.
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        foreach (['A', 'B', 'C'] as $title) {
            $this->withHeaders($h)->postJson('/api/v1/teacher/courses', ['title' => $title])->assertStatus(201);
        }

        $this->assertSame(3, Course::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_null_limit_key_means_unlimited(): void
    {
        // A plan that constrains students but leaves courses null (unlimited).
        $this->assignPlan(['max_students' => 5]); // max_courses omitted → null
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        foreach (['A', 'B', 'C'] as $title) {
            $this->withHeaders($h)->postJson('/api/v1/teacher/courses', ['title' => $title])->assertStatus(201);
        }

        $this->assertSame(3, Course::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_storage_limit_is_enforced_from_recorded_media_bytes(): void
    {
        $this->assignPlan(['storage_mb' => 10]);

        // 8 MB already stored for this tenant.
        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'title' => 'v']);
        $asset->tenant_id = $this->tenant->id;
        $asset->size_bytes = 8 * 1048576;
        $asset->save();

        $guard = app(PlanLimitGuard::class);

        $check = $guard->check($this->tenant->id, 'storage_mb');
        $this->assertSame(8, $check['used']);
        $this->assertSame(2, $check['remaining']);

        // A further 2 MB fits (8 + 2 = 10); 5 MB overflows the quota.
        $guard->ensure($this->tenant->id, 'storage_mb', 2);

        try {
            $guard->ensure($this->tenant->id, 'storage_mb', 5);
            $this->fail('Expected a plan_limit_reached DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }
}
