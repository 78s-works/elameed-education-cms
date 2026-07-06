<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    public function test_wallet_adjustment_is_audited_and_readable_by_teacher(): void
    {
        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/wallet/adjust", [
            'amount_minor' => 5000, 'direction' => 'credit', 'reason' => 'Welcome gift',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'wallet.adjust']);

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/audit-logs')
            ->assertOk()->assertJsonPath('data.0.action', 'wallet.adjust');
    }

    public function test_teacher_audit_log_is_tenant_scoped(): void
    {
        // An audit entry in another tenant must not appear.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        AuditLog::create([
            'tenant_id' => $other->id, 'action' => 'secret.action', 'created_at' => now(),
        ]);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $res = $this->withHeaders($this->h)->getJson('/api/v1/teacher/audit-logs')->assertOk();
        $actions = collect($res->json('data'))->pluck('action')->all();
        $this->assertNotContains('secret.action', $actions);
    }
}
