<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_non_admin_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create()); // not a platform admin

        $this->getJson('/api/v1/admin/tenants')->assertStatus(403);
    }

    public function test_admin_can_create_tenant_with_owner(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $res = $this->postJson('/api/v1/admin/tenants', [
            'name' => 'Mr Ahmed Academy',
            'slug' => 'ahmed',
            'status' => 'active',
            'owner' => [
                'name' => 'Ahmed', 'phone' => '01111111111', 'password' => 'password123',
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.slug', 'ahmed')
            ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($res->json('data.owner_user_id'));
        $this->assertSame('ahmed.elameed.app', $res->json('data.primary_host'));
    }

    public function test_admin_can_suspend_tenant(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $tenant = Tenant::create(['slug' => 'x', 'name' => 'X', 'status' => TenantStatus::Active]);

        $this->putJson("/api/v1/admin/tenants/{$tenant->uuid}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_overview_returns_cross_tenant_totals(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        Tenant::create(['slug' => 'a', 'name' => 'A', 'status' => TenantStatus::Active]);
        Tenant::create(['slug' => 'b', 'name' => 'B', 'status' => TenantStatus::Suspended]);

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.teachers', 2)
            ->assertJsonStructure(['data' => ['teachers', 'students', 'courses', 'gross_earnings_minor', 'tenants_by_status']]);
    }
}
