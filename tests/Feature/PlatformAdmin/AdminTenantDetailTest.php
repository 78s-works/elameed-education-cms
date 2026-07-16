<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /admin/tenants/{uuid} returns the full cross-tenant 360 of an academy —
 * tenant, owner teacher, branding, subscription + usage, and activity stats —
 * so the platform admin sees all information about a teacher and their tenant.
 */
class AdminTenantDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function member(Tenant $tenant, TenantUserRole $role, array $userAttrs = []): User
    {
        $user = User::factory()->create($userAttrs);
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_show_returns_full_tenant_and_teacher_detail(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed Academy', 'status' => TenantStatus::Active]);
        $tenant->domains()->create(['host' => 'ahmed.elameed.app', 'type' => 'subdomain', 'is_primary' => true]);

        $teacher = $this->member($tenant, TenantUserRole::Teacher, ['phone' => '01555555555', 'name' => 'Ahmed']);
        $tenant->forceFill(['owner_user_id' => $teacher->id])->save();
        $this->member($tenant, TenantUserRole::Student);

        $profile = new TeacherProfile(['primary_color' => '#1D4ED8', 'bio' => 'أكاديمية أحمد', 'layout' => 'classic']);
        $profile->tenant_id = $tenant->id;
        $profile->save();

        $course = new Course(['title' => 'Mechanics', 'slug' => 'mechanics', 'price_minor' => 1000, 'visibility' => 'visible']);
        $course->tenant_id = $tenant->id;
        $course->save();

        $package = SubscriptionPackage::create([
            'slug' => 'growth', 'name' => 'Growth', 'price_minor' => 150000, 'interval' => 'monthly', 'trial_days' => 0,
            'limits' => ['max_students' => 100, 'max_courses' => 30, 'storage_mb' => 5000, 'max_assistants' => 3],
        ]);
        app(SubscriptionService::class)->assign($tenant, $package);

        $this->getJson("/api/v1/admin/tenants/{$tenant->uuid}")
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'ahmed')
            ->assertJsonPath('data.tenant.domains.0.host', 'ahmed.elameed.app')
            ->assertJsonPath('data.owner.phone', '01555555555')
            ->assertJsonPath('data.branding.primary_color', '#1D4ED8')
            ->assertJsonPath('data.subscription.package.slug', 'growth')
            ->assertJsonPath('data.usage.max_students.limit', 100)
            ->assertJsonPath('data.usage.max_students.used', 1)
            ->assertJsonPath('data.stats.students', 1)
            ->assertJsonPath('data.stats.courses', 1)
            ->assertJsonPath('data.stats.published_courses', 1);
    }

    public function test_show_still_requires_admin_and_admin_host(): void
    {
        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed', 'status' => TenantStatus::Active]);

        // Non-admin is forbidden.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/admin/tenants/{$tenant->uuid}")->assertStatus(403);

        // Admin off the central host → 404 (host gate).
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->getJson("http://ahmed.elameed.app/api/v1/admin/tenants/{$tenant->uuid}")->assertNotFound();
    }
}
