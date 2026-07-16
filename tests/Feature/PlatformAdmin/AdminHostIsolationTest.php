<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The platform-admin console (/admin/*) must be reachable ONLY on a central
 * host — admin.<base_domain>, the apex, or a trusted local host in dev. A
 * teacher academy's subdomain or custom domain must never serve it, even for a
 * valid platform admin. Guards EnsureCentralHost + the reserved-slug rule.
 *
 * Hosts are controlled with absolute URLs (relative URLs resolve to APP_URL,
 * whose host is a trusted local IP here — see EnsureRegisteredDomain notes).
 */
class AdminHostIsolationTest extends TestCase
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

    public function test_admin_console_is_reachable_on_the_central_admin_host(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('http://admin.elameed.app/api/v1/admin/tenants')
            ->assertOk();
    }

    public function test_admin_console_is_blocked_on_a_teacher_subdomain(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('http://ahmed.elameed.app/api/v1/admin/tenants')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_admin_console_is_blocked_on_a_teacher_custom_domain(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('http://academy.com/api/v1/admin/tenants')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_valid_admin_token_still_cannot_open_the_console_off_host(): void
    {
        // The strongest guarantee: a genuine platform-admin bearer replayed on a
        // teacher domain is still refused before it can create anything.
        Sanctum::actingAs($this->admin());

        $this->postJson('http://ahmed.elameed.app/api/v1/admin/tenants', [
            'name' => 'X', 'slug' => 'x', 'status' => 'active',
        ])->assertNotFound();

        $this->assertDatabaseMissing('tenants', ['slug' => 'x']);
    }

    public function test_only_central_hosts_pass_once_local_hosts_are_not_trusted(): void
    {
        // Simulate production: local hosts (incl. bare IPs) are no longer trusted.
        config(['tenancy.guard.trust_local_domains' => false]);
        Sanctum::actingAs($this->admin());

        $this->getJson('http://admin.elameed.app/api/v1/admin/tenants')->assertOk();

        $this->getJson('http://127.0.0.1/api/v1/admin/tenants')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_platform_admin_token_has_no_access_to_tenant_scoped_routes(): void
    {
        // A platform admin's authority is exercised ONLY through /admin/* on the
        // admin host. Their token carries no implicit role or membership inside a
        // teacher academy, so tenant-scoped routes on a tenant host refuse it.
        Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        Sanctum::actingAs($this->admin());

        // active-gated route (EnsureActiveMembership) — no membership here → 403.
        $this->getJson('http://demo.elameed.app/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        // role-gated route (EnsureTenantRole) — not a teacher here → 403.
        $this->getJson('http://demo.elameed.app/api/v1/teacher/profile')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_reserved_slug_cannot_be_provisioned_as_a_tenant(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('http://admin.elameed.app/api/v1/admin/tenants', [
            'name' => 'Admin Impersonator', 'slug' => 'admin', 'status' => 'active',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('tenants', ['slug' => 'admin']);
    }
}
