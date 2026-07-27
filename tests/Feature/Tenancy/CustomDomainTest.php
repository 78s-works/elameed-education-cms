<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Custom domains Part 2 (M02): teacher CRUD over tenant_domains, host resolution
 * for a registered custom domain, dynamic CORS trust, validation, and tenant
 * isolation.
 */
class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function teacher(?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_teacher_registers_a_custom_domain_and_gets_dns_instructions(): void
    {
        Sanctum::actingAs($this->teacher());

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/domains', [
            'host' => 'academy.example.com',
        ])->assertStatus(201)
            ->assertJsonPath('data.host', 'academy.example.com')
            ->assertJsonPath('data.type', 'custom')
            ->assertJsonPath('data.ssl_status', 'pending')
            ->assertJsonPath('data.dns.type', 'CNAME')
            ->assertJsonPath('data.dns.name', 'academy.example.com');

        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $this->tenant->id, 'host' => 'academy.example.com', 'type' => 'custom',
        ]);
    }

    public function test_a_registered_custom_domain_resolves_to_the_tenant(): void
    {
        Sanctum::actingAs($this->teacher());
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/domains', ['host' => 'academy.example.com'])
            ->assertStatus(201);

        // Now a request arriving on that Host (no X-Tenant) resolves to `demo`.
        $this->withHeaders(['Host' => 'academy.example.com'])->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.slug', 'demo');
    }

    public function test_a_registered_origin_is_trusted_by_cors(): void
    {
        Sanctum::actingAs($this->teacher());
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/domains', ['host' => 'academy.example.com'])
            ->assertStatus(201);

        // A cross-origin call from the registered domain gets the CORS header back.
        $this->withHeaders([
            'Host' => 'academy.example.com',
            'Origin' => 'https://academy.example.com',
        ])->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://academy.example.com');
    }

    public function test_an_unregistered_origin_is_not_reflected(): void
    {
        // No domain registered for evil.example — CORS must not echo it.
        $response = $this->withHeaders(['X-Tenant' => 'demo', 'Origin' => 'https://evil.example'])
            ->getJson('/api/v1/tenant/context');

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_platform_and_duplicate_hosts_are_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        // A *.elameed.app subdomain and the apex are platform-managed.
        $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'x.elameed.app'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'elameed.app'])->assertStatus(422);
        // Malformed.
        $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'not a domain'])->assertStatus(422);

        // First registration OK; the same host again is rejected.
        $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'academy.example.com'])->assertStatus(201);
        $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'academy.example.com'])->assertStatus(422);
    }

    public function test_teacher_lists_and_deletes_own_custom_domain(): void
    {
        Sanctum::actingAs($this->teacher());
        $h = ['X-Tenant' => 'demo'];

        $uuid = $this->withHeaders($h)->postJson('/api/v1/teacher/domains', ['host' => 'academy.example.com'])
            ->json('data.uuid');

        $this->withHeaders($h)->getJson('/api/v1/teacher/domains')
            ->assertOk()
            ->assertJsonPath('data.0.host', 'academy.example.com');

        $this->withHeaders($h)->deleteJson("/api/v1/teacher/domains/{$uuid}")->assertNoContent();
        $this->assertDatabaseMissing('tenant_domains', ['host' => 'academy.example.com']);
    }

    public function test_platform_subdomain_cannot_be_removed(): void
    {
        $subdomain = TenantDomain::create([
            'tenant_id' => $this->tenant->id, 'host' => 'demo.elameed.app', 'type' => 'subdomain', 'is_primary' => true,
        ]);
        Sanctum::actingAs($this->teacher());

        $this->withHeaders(['X-Tenant' => 'demo'])->deleteJson("/api/v1/teacher/domains/{$subdomain->uuid}")
            ->assertStatus(422);
    }

    public function test_a_teacher_cannot_delete_another_tenants_domain(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreign = TenantDomain::create([
            'tenant_id' => $other->id, 'host' => 'other.example.com', 'type' => 'custom',
        ]);

        Sanctum::actingAs($this->teacher()); // teacher of `demo`
        $this->withHeaders(['X-Tenant' => 'demo'])->deleteJson("/api/v1/teacher/domains/{$foreign->uuid}")
            ->assertStatus(404);
    }
}
