<?php

namespace Tests\Feature\Identity;

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
 * Assistant management + granular RBAC (M18): teacher CRUD over assistants, and
 * per-permission enforcement of the shared teacher surface.
 */
class AssistantManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role, array $permissions = [], ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'permissions' => $permissions !== [] ? $permissions : null,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_teacher_creates_an_assistant_with_permissions_and_gets_a_temp_password(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $res = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/assistants', [
            'name' => 'Omar', 'phone' => '01099999999', 'permissions' => ['students'],
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Omar')
            ->assertJsonPath('data.permissions', ['students'])
            ->assertJsonPath('data.status', 'active');

        $this->assertNotEmpty($res->json('data.temporary_password'));
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id, 'role' => 'assistant', 'status' => 'active',
        ]);
    }

    public function test_assistant_reaches_only_granted_surfaces(): void
    {
        // Granted `students`, NOT `centers`.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    public function test_assistant_with_no_permissions_is_forbidden_everywhere_shared(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, []));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    public function test_assistant_cannot_manage_other_assistants(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));

        // Assistant management is teacher-only (role:teacher).
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/teacher/assistants')->assertStatus(403);
    }

    public function test_me_exposes_effective_permissions(): void
    {
        $assistant = $this->member(TenantUserRole::Assistant, ['students']);
        Sanctum::actingAs($assistant);
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current.role', 'assistant')
            ->assertJsonPath('data.current.permissions', ['students']);

        // A teacher implicitly holds the full catalog.
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $perms = $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me')->json('data.current.permissions');
        $this->assertContains('students', $perms);
        $this->assertContains('centers', $perms);
    }

    public function test_teacher_can_rescope_permissions_and_suspend(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $assistant = $this->member(TenantUserRole::Assistant, ['students']);
        Sanctum::actingAs($teacher);
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->patchJson("/api/v1/teacher/assistants/{$assistant->uuid}", [
            'permissions' => ['students', 'centers'], 'status' => 'suspended',
        ])->assertOk()
            ->assertJsonPath('data.permissions', ['students', 'centers'])
            ->assertJsonPath('data.status', 'suspended');

        // Suspended → the assistant's access is cut immediately (active middleware).
        Sanctum::actingAs($assistant);
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
    }

    public function test_max_assistants_limit_is_enforced(): void
    {
        $package = SubscriptionPackage::create([
            'slug' => 'solo', 'name' => 'Solo', 'price_minor' => 0,
            'interval' => 'monthly', 'trial_days' => 0, 'limits' => ['max_assistants' => 1],
        ]);
        app(SubscriptionService::class)->assign($this->tenant, $package);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson('/api/v1/teacher/assistants', ['name' => 'A', 'phone' => '01000000011'])
            ->assertStatus(201);
        $this->withHeaders($h)->postJson('/api/v1/teacher/assistants', ['name' => 'B', 'phone' => '01000000012'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details.key', 'max_assistants');
    }

    public function test_cross_tenant_assistant_is_not_found(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignAssistant = $this->member(TenantUserRole::Assistant, ['students'], $other);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/teacher/assistants/{$foreignAssistant->uuid}")
            ->assertStatus(404);
    }

    public function test_student_cannot_manage_assistants(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/teacher/assistants')->assertStatus(403);
    }
}
