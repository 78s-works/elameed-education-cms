<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Teacher subscription packages (M03). Admin CRUD + assignment run on the
 * (central) admin surface; the teacher read view is tenant-scoped. Admin routes
 * use relative URLs (host resolves to a trusted local IP = central).
 */
class SubscriptionPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function makeMember(Tenant $tenant, TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function makePackage(array $attrs = []): SubscriptionPackage
    {
        return SubscriptionPackage::create(array_merge([
            'slug' => 'growth',
            'name' => 'Growth',
            'price_minor' => 150000,
            'interval' => 'monthly',
            'trial_days' => 14,
            'limits' => ['max_students' => 100, 'max_courses' => 10, 'storage_mb' => 5000, 'max_assistants' => 2],
        ], $attrs));
    }

    // --- Admin package CRUD --------------------------------------------------

    public function test_admin_can_create_and_list_packages(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/packages', [
            'name' => 'Growth',
            'slug' => 'growth',
            'price_minor' => 150000,
            'interval' => 'monthly',
            'trial_days' => 14,
            'limits' => ['max_students' => 2000, 'max_courses' => 30],
        ])->assertStatus(201)
            ->assertJsonPath('data.slug', 'growth')
            ->assertJsonPath('data.price_minor', 150000)
            ->assertJsonPath('data.limits.max_students', 2000)
            ->assertJsonPath('data.limits.storage_mb', null); // unset key = unlimited

        $this->getJson('/api/v1/admin/packages')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'growth');
    }

    public function test_admin_can_update_and_retire_a_package(): void
    {
        Sanctum::actingAs($this->admin());
        $package = $this->makePackage();

        $this->putJson("/api/v1/admin/packages/{$package->uuid}", ['price_minor' => 200000, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.price_minor', 200000)
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/admin/packages/{$package->uuid}")->assertNoContent();
        $this->assertSoftDeleted('subscription_packages', ['id' => $package->id]);
        $this->getJson('/api/v1/admin/packages')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_non_admin_cannot_manage_packages(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/packages')->assertStatus(403);
    }

    public function test_validation_rejects_a_bad_package(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/packages', ['name' => 'X', 'slug' => 'Bad Slug', 'price_minor' => -5])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    // --- Assigning a package to a tenant ------------------------------------

    public function test_admin_can_assign_a_package_to_a_tenant(): void
    {
        Sanctum::actingAs($this->admin());
        $tenant = $this->makeTenant('demo');
        $package = $this->makePackage(['trial_days' => 14]);

        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/subscription", ['package_uuid' => $package->uuid])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.package.slug', 'growth')
            ->assertJsonPath('data.price_minor', 150000);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'package_id' => $package->id]);
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id, 'package_id' => $package->id, 'status' => 'trialing',
        ]);
    }

    public function test_assigning_a_new_package_supersedes_the_previous(): void
    {
        Sanctum::actingAs($this->admin());
        $tenant = $this->makeTenant('demo');
        $a = $this->makePackage(['slug' => 'growth', 'trial_days' => 0]);
        $b = $this->makePackage(['slug' => 'scale', 'name' => 'Scale', 'price_minor' => 500000, 'trial_days' => 0]);

        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/subscription", ['package_uuid' => $a->uuid])->assertStatus(201);
        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/subscription", ['package_uuid' => $b->uuid])->assertStatus(201);

        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'package_id' => $a->id, 'status' => 'canceled']);
        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'package_id' => $b->id, 'status' => 'active']);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'package_id' => $b->id]);
    }

    public function test_discount_override_locks_a_custom_price(): void
    {
        Sanctum::actingAs($this->admin());
        $tenant = $this->makeTenant('demo');
        $package = $this->makePackage(['trial_days' => 0]);

        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/subscription", [
            'package_uuid' => $package->uuid, 'price_minor' => 99000, 'discount_reason' => 'new-teacher',
        ])->assertStatus(201)->assertJsonPath('data.price_minor', 99000);
    }

    // --- Teacher read view + isolation --------------------------------------

    public function test_teacher_sees_own_subscription_with_usage(): void
    {
        $tenant = $this->makeTenant('demo');
        $teacher = $this->makeMember($tenant, TenantUserRole::Teacher);
        $this->makeMember($tenant, TenantUserRole::Student); // one active student
        $package = $this->makePackage(['trial_days' => 0]);
        app(SubscriptionService::class)->assign($tenant, $package);

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.package.slug', 'growth')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.usage.max_students.limit', 100)
            ->assertJsonPath('data.usage.max_students.used', 1)
            ->assertJsonPath('data.usage.max_students.remaining', 99)
            ->assertJsonPath('data.usage.max_courses.used', 0);
    }

    public function test_teacher_without_a_subscription_gets_null(): void
    {
        $tenant = $this->makeTenant('demo');
        $teacher = $this->makeMember($tenant, TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription', null);
    }

    public function test_teacher_cannot_see_another_tenants_subscription(): void
    {
        $a = $this->makeTenant('academy-a');
        $b = $this->makeTenant('academy-b');
        app(SubscriptionService::class)->assign($a, $this->makePackage(['trial_days' => 0]));

        $teacherB = $this->makeMember($b, TenantUserRole::Teacher);
        Sanctum::actingAs($teacherB);

        // B has no plan of its own; A's plan must not leak across the tenant filter.
        $this->withHeader('X-Tenant', 'academy-b')->getJson('/api/v1/teacher/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription', null);
    }
}
