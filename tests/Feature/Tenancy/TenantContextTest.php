<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // resolution cache must not bleed across tests
    }

    public function test_resolves_tenant_via_x_tenant_header(): void
    {
        Tenant::create(['slug' => 'demo', 'name' => 'Demo Academy', 'status' => TenantStatus::Active]);

        $response = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/tenant/context');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'demo')
            ->assertJsonPath('data.name', 'Demo Academy')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.locale.default', 'ar');
    }

    public function test_resolves_tenant_via_subdomain_host(): void
    {
        Tenant::create(['slug' => 'ahmed', 'name' => "Ahmed's Academy", 'status' => TenantStatus::Active]);

        // Host = <slug>.<base_domain>; the first label is parsed as the slug.
        $response = $this->getJson('http://ahmed.elameed.app/api/v1/tenant/context');

        $response->assertOk()->assertJsonPath('data.slug', 'ahmed');
    }

    public function test_unknown_tenant_returns_404_error_envelope(): void
    {
        $response = $this->withHeader('X-Tenant', 'does-not-exist')
            ->getJson('/api/v1/tenant/context');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'tenant_not_found');
    }
}
