<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Http\Middleware\EnsureRegisteredDomain;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Covers EnsureRegisteredDomain: only requests whose Host maps to an ACTIVE
 * tenant reach tenant routes; everything else is rejected before routing. The
 * public /api/v1/tenant/context endpoint is used as a representative
 * tenant-scoped route so the gate is exercised without auth.
 */
class DomainGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // resolution + guard caches must not bleed across tests
    }

    private function activeTenant(string $slug = 'demo'): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function addDomain(Tenant $tenant, string $host): void
    {
        $tenant->domains()->create([
            'host' => $host,
            'type' => TenantDomainType::Custom,
            'is_primary' => true,
        ]);
    }

    private function context(string $host, array $headers = [])
    {
        return $this->withHeaders($headers)->getJson("http://{$host}/api/v1/tenant/context");
    }

    // --- Valid ---------------------------------------------------------------

    public function test_active_tenant_custom_domain_is_allowed(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'academy.com');

        $this->context('academy.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'demo');
    }

    public function test_active_tenant_subdomain_is_allowed(): void
    {
        $this->activeTenant('ahmed');

        $this->context('ahmed.elameed.app')
            ->assertOk()
            ->assertJsonPath('data.slug', 'ahmed');
    }

    // --- Unknown / inactive --------------------------------------------------

    public function test_unknown_domain_is_rejected(): void
    {
        $this->context('nobody-here.com')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_unknown_subdomain_is_rejected(): void
    {
        $this->activeTenant('ahmed');

        // A subdomain of the base whose label matches no tenant slug.
        $this->context('stranger.elameed.app')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_inactive_tenant_domain_is_forbidden(): void
    {
        $tenant = Tenant::create(['slug' => 'susp', 'name' => 'Susp', 'status' => TenantStatus::Suspended]);
        $this->addDomain($tenant, 'suspended.com');

        $this->context('suspended.com')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    // --- www handling --------------------------------------------------------

    public function test_www_prefix_resolves_domain_registered_without_www(): void
    {
        $tenant = $this->activeTenant('school');
        $this->addDomain($tenant, 'school.com'); // stored without www

        $this->context('www.school.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'school');
    }

    public function test_apex_resolves_domain_registered_with_www(): void
    {
        $tenant = $this->activeTenant('college');
        $this->addDomain($tenant, 'www.college.com'); // stored with www

        $this->context('college.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'college');
    }

    public function test_www_prefixed_subdomain_resolves(): void
    {
        $this->activeTenant('ahmed');

        $this->context('www.ahmed.elameed.app')
            ->assertOk()
            ->assertJsonPath('data.slug', 'ahmed');
    }

    // --- Central / admin -----------------------------------------------------

    public function test_central_apex_domain_bypasses_the_gate(): void
    {
        // Reaches the controller (which reports no tenant) rather than being
        // blocked by the gate — proven by the distinct error code.
        $this->context('elameed.app')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'tenant_not_found');
    }

    public function test_admin_domain_bypasses_the_gate(): void
    {
        $this->context('admin.elameed.app')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'tenant_not_found');
    }

    // --- Local development ---------------------------------------------------

    public function test_local_host_is_allowed_and_uses_header_override(): void
    {
        $this->activeTenant('demo');

        // localhost is exempt in non-production; the X-Tenant dev override then
        // resolves the tenant (existing local-dev workflow keeps working).
        $this->context('localhost', ['X-Tenant' => 'demo'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'demo');
    }

    public function test_local_host_is_not_exempt_in_production(): void
    {
        // Simulate production: local hosts are no longer trusted, so a request
        // on localhost must be a registered domain like any other.
        config(['tenancy.guard.trust_local_domains' => false]);

        $this->context('localhost', ['X-Tenant' => 'demo'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    // --- Manipulated / invalid Host -----------------------------------------

    public function test_spoofed_x_forwarded_host_cannot_bypass_the_gate(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'academy.com');

        // The attacker hits an unregistered host but forges X-Forwarded-Host to a
        // real tenant domain. No proxies are trusted, so getHost() ignores it.
        $this->context('evil.example', ['X-Forwarded-Host' => 'academy.com'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_x_tenant_header_cannot_bypass_the_gate_on_a_public_host(): void
    {
        $this->activeTenant('demo');

        // The dev header override is active in this environment, but the gate
        // runs on the Host only — so it still blocks an unregistered domain.
        $this->context('evil.example', ['X-Tenant' => 'demo'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_malformed_host_header_is_rejected(): void
    {
        // The gate reads the host via getHost(), which validates the Host header
        // and rejects a malformed one outright — before any tenant lookup runs.
        $request = Request::create('http://placeholder.test/api/v1/tenant/context');
        $request->headers->set('Host', 'bad host name');

        $this->expectException(SuspiciousOperationException::class);

        app(EnsureRegisteredDomain::class)->handle($request, static fn (): Response => new Response('ok'));
    }

    // --- Caching & invalidation ---------------------------------------------

    public function test_decision_is_cached(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'cached.com');

        $this->context('cached.com')->assertOk();
        $this->assertSame($tenant->id.'|active', Cache::get('tenant_domain_guard:cached.com'));

        $this->context('missing.com')->assertNotFound();
        $this->assertSame('none', Cache::get('tenant_domain_guard:missing.com'));
    }

    public function test_adding_a_domain_refreshes_the_cache(): void
    {
        $tenant = $this->activeTenant('demo');

        $this->context('late.com')->assertNotFound(); // negative-cached

        $this->addDomain($tenant, 'late.com'); // observer clears the negative entry

        $this->context('late.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'demo');
    }

    public function test_deleting_a_domain_refreshes_the_cache(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'temp.com');

        $this->context('temp.com')->assertOk(); // positive-cached

        $tenant->domains()->where('host', 'temp.com')->first()->delete();

        $this->context('temp.com')->assertNotFound();
    }

    public function test_deactivating_a_tenant_refreshes_the_cache(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'flip.com');

        $this->context('flip.com')->assertOk(); // cached as active

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->context('flip.com')->assertForbidden();
    }

    public function test_activating_a_tenant_refreshes_the_cache(): void
    {
        $tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Suspended]);
        $this->addDomain($tenant, 'wake.com');

        $this->context('wake.com')->assertForbidden(); // cached as inactive

        $tenant->update(['status' => TenantStatus::Active]);

        $this->context('wake.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'demo');
    }
}
