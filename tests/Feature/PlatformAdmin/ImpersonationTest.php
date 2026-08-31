<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * Supervised impersonation (ADM-17). The point of the feature is support being
 * able to SEE a teacher's panel; the point of these tests is that it can only
 * ever see — a token minted here must not be able to change anything, and both
 * ends of the session must be attributable.
 */
class ImpersonationTest extends TestCase
{
    use GrantsTenantRoles, RefreshDatabase;

    private function academy(): array
    {
        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed Academy', 'status' => TenantStatus::Active]);
        $tenant->domains()->create(['host' => 'ahmed.elameed.app', 'type' => 'subdomain', 'is_primary' => true]);

        $owner = User::factory()->create(['name' => 'Ahmed']);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $owner->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
        $tenant->forceFill(['owner_user_id' => $owner->id])->save();

        // Authority comes from a role, never the membership row (M20) — the
        // impersonated session must read through the owner's real gates.
        $this->grantPermissions($owner, $tenant, ['content.academic_years.manage']);

        return [$tenant, $owner];
    }

    public function test_admin_gets_a_read_only_token_and_the_start_is_audited(): void
    {
        [$tenant] = $this->academy();
        $admin = User::factory()->platformAdmin()->create(['name' => 'Nour']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.tenant.name', 'Ahmed Academy')
            ->assertJsonPath('data.owner.name', 'Ahmed');

        $this->assertNotEmpty($response->json('data.token'));

        $entry = AuditLog::query()->where('action', 'tenant.impersonation.started')->firstOrFail();
        // The ADMIN is the actor, even though the token belongs to the owner.
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame($tenant->id, $entry->tenant_id);
    }

    /** A real bearer token: Sanctum::actingAs would pin the guard's user for the
     *  rest of the test, and the impersonation token would never be read. */
    private function adminToken(): string
    {
        return User::factory()->platformAdmin()->create()->createToken('api')->plainTextToken;
    }

    public function test_the_impersonation_token_can_read_but_never_write(): void
    {
        [$tenant] = $this->academy();

        $token = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->postJson("/api/v1/admin/tenants/{$tenant->uuid}/impersonate")
            ->assertOk()->json('data.token');

        // Reading the academy's own panel is the whole point.
        // The guard memoises the user it resolved for the admin's request; without
        // this the impersonation token is never actually read.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => "Bearer {$token}", 'X-Tenant' => 'ahmed'];

        $this->getJson('/api/v1/teacher/academic-years', $headers)->assertOk();

        // Anything that writes is refused before it reaches the controller,
        // with a reason the panel can show.
        $this->postJson('/api/v1/teacher/academic-years', ['label' => 'nope'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'impersonation_read_only');
    }

    public function test_exiting_revokes_the_token_and_is_audited(): void
    {
        [$tenant] = $this->academy();

        $token = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->postJson("/api/v1/admin/tenants/{$tenant->uuid}/impersonate")
            ->assertOk()->json('data.token');

        // The guard memoises the user it resolved for the admin's request; without
        // this the impersonation token is never actually read.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => "Bearer {$token}", 'X-Tenant' => 'ahmed'];

        $this->postJson('/api/v1/impersonation/stop', [], $headers)->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.impersonation.ended']);

        // The revoked token is dead — a supervised session cannot outlive its exit.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/teacher/academic-years', $headers)->assertStatus(401);
    }

    public function test_impersonation_requires_platform_admin(): void
    {
        [$tenant] = $this->academy();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/impersonate")->assertStatus(403);
    }
}
