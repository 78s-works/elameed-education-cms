<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    private function makeProfile(Tenant $tenant, array $attrs): TeacherProfile
    {
        $profile = new TeacherProfile($attrs);
        $profile->tenant_id = $tenant->id; // no request context in tests
        $profile->save();

        return $profile;
    }

    public function test_teacher_can_view_and_update_profile(): void
    {
        $tenant = $this->makeTenant('demo');
        $teacher = $this->makeMember($tenant, TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/profile', [
            'primary_color' => '#1D4ED8',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'contact' => ['phone' => '01000000000'],
        ])->assertOk()->assertJsonPath('data.primary_color', '#1D4ED8');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#1D4ED8')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.com/logo.png');
    }

    public function test_invalid_color_is_rejected(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/profile', [
            'primary_color' => 'red',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_student_cannot_access_teacher_profile(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Student));

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unauthenticated_cannot_access_teacher_profile(): void
    {
        $this->makeTenant('demo');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertStatus(401);
    }

    /**
     * The core isolation guarantee — on MySQL the BelongsToTenant scope is the
     * ONLY guard, so this must hold at both the HTTP and the model layer.
     */
    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');
        $this->makeProfile($tenantA, ['primary_color' => '#AAAAAA']);
        $this->makeProfile($tenantB, ['primary_color' => '#BBBBBB']);

        $teacherA = $this->makeMember($tenantA, TenantUserRole::Teacher);

        // HTTP layer: teacher A on tenant A's host only ever sees A's profile.
        Sanctum::actingAs($teacherA);
        $this->withHeader('X-Tenant', 'alpha')->getJson('/api/v1/teacher/profile')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#AAAAAA');

        // Model layer: the global scope filters strictly by the current tenant.
        $context = app(TenantContext::class);

        $context->setTenant($tenantA);
        $this->assertSame(['#AAAAAA'], TeacherProfile::query()->pluck('primary_color')->all());

        $context->setTenant($tenantB);
        $this->assertSame(['#BBBBBB'], TeacherProfile::query()->pluck('primary_color')->all());
    }

    public function test_landing_update_and_context_reflects_visible_sections(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'landing_sections' => [
                ['key' => 'courses', 'visible' => true],
                ['key' => 'about', 'visible' => false],
            ],
        ])->assertOk();

        // Public context returns only visible section keys, in order.
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.branding.landing_sections', ['courses']);
    }
}
