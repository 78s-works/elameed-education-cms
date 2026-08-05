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

    /** Create a course directly (the /teacher/courses endpoint was retired, VD §7). */
    private function makeCourse(string $title): void
    {
        $course = new Course(['title' => $title, 'visibility' => 'visible']);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
    }

    /**
     * Course authoring moved off the API (units/courses teacher CRUD retired), so
     * the max_courses limit is asserted against PlanLimitGuard directly — the same
     * guard the (now-removed) CourseController used to call.
     */
    public function test_course_creation_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $this->assignPlan(['max_courses' => 1]);
        $guard = app(PlanLimitGuard::class);

        // 0 used, limit 1 → one more is allowed.
        $guard->ensure($this->tenant->id, 'max_courses', 1);
        $this->makeCourse('First');

        // 1 used, limit 1 → the next one is blocked.
        $check = $guard->check($this->tenant->id, 'max_courses');
        $this->assertSame(1, $check['used']);
        $this->assertSame(0, $check['remaining']);

        try {
            $guard->ensure($this->tenant->id, 'max_courses', 1);
            $this->fail('Expected a plan_limit_reached DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode);
            $this->assertSame(403, $e->status);
            $this->assertSame('max_courses', $e->details['key']);
        }

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
        // No subscription assigned at all → the guard never blocks.
        $guard = app(PlanLimitGuard::class);

        foreach (['A', 'B', 'C'] as $title) {
            $this->makeCourse($title);
        }

        $check = $guard->check($this->tenant->id, 'max_courses');
        $this->assertNull($check['limit']);
        $this->assertTrue($check['allowed']);
        $guard->ensure($this->tenant->id, 'max_courses', 100); // no throw
    }

    public function test_null_limit_key_means_unlimited(): void
    {
        // A plan that constrains students but leaves courses null (unlimited).
        $this->assignPlan(['max_students' => 5]); // max_courses omitted → null
        $guard = app(PlanLimitGuard::class);

        foreach (['A', 'B', 'C'] as $title) {
            $this->makeCourse($title);
        }

        $this->assertNull($guard->check($this->tenant->id, 'max_courses')['limit']);
        $guard->ensure($this->tenant->id, 'max_courses', 100); // no throw
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
