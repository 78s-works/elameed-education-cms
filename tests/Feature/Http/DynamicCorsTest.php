<?php

namespace Tests\Feature\Http;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers App\Support\Http\HandleDynamicCors: the caller's Origin is reflected in
 * Access-Control-Allow-Origin only when it is a configured origin, a subdomain
 * of the platform base domain, or a registered ACTIVE tenant custom domain.
 * Everything else gets no CORS header (browser blocks it).
 */
class DynamicCorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'cors.allowed_origins' => ['https://front.edu.78sworks.io'],
            'tenancy.base_domain' => 'edu.78sworks.io',
        ]);
    }

    private function activeTenant(string $slug = 'demo'): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function addDomain(Tenant $tenant, string $host): void
    {
        $tenant->domains()->create(['host' => $host, 'type' => TenantDomainType::Custom, 'is_primary' => true]);
    }

    private function preflight(string $origin)
    {
        return $this->json('OPTIONS', 'http://localhost/api/v1/auth/login', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-tenant',
        ]);
    }

    // --- Preflight: allowed origins -----------------------------------------

    public function test_configured_origin_is_reflected_on_preflight(): void
    {
        $this->preflight('https://front.edu.78sworks.io')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://front.edu.78sworks.io')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    public function test_base_domain_subdomain_is_allowed_on_preflight(): void
    {
        $this->preflight('https://ahmed.edu.78sworks.io')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://ahmed.edu.78sworks.io');
    }

    public function test_registered_tenant_custom_domain_is_allowed_on_preflight(): void
    {
        $tenant = $this->activeTenant('demo');
        $this->addDomain($tenant, 'academy.com');

        $this->preflight('https://academy.com')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://academy.com');
    }

    // --- Preflight: denied origins ------------------------------------------

    public function test_unregistered_origin_gets_no_cors_header(): void
    {
        $this->preflight('https://evil.example')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_suspended_tenant_custom_domain_is_denied(): void
    {
        $tenant = Tenant::create(['slug' => 'susp', 'name' => 'Susp', 'status' => TenantStatus::Suspended]);
        $this->addDomain($tenant, 'suspended.com');

        $this->preflight('https://suspended.com')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    // --- Real requests -------------------------------------------------------

    public function test_real_request_from_allowed_origin_carries_cors_headers(): void
    {
        $this->activeTenant('demo');

        $response = $this->withHeaders(['Origin' => 'https://front.edu.78sworks.io', 'X-Tenant' => 'demo'])
            ->getJson('http://localhost/api/v1/tenant/context');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://front.edu.78sworks.io');
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_real_request_from_disallowed_origin_has_no_cors_header(): void
    {
        $this->activeTenant('demo');

        $this->withHeaders(['Origin' => 'https://evil.example', 'X-Tenant' => 'demo'])
            ->getJson('http://localhost/api/v1/tenant/context')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_error_responses_still_carry_cors_headers(): void
    {
        // No tenant resolved → the endpoint errors, but the browser must still be
        // able to read the error, so the CORS header has to be present.
        $this->withHeaders(['Origin' => 'https://front.edu.78sworks.io'])
            ->getJson('http://localhost/api/v1/tenant/context')
            ->assertHeader('Access-Control-Allow-Origin', 'https://front.edu.78sworks.io');
    }
}
