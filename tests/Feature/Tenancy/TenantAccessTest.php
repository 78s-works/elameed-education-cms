<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantAccessTest extends TestCase
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
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_defaults_are_open_for_a_fresh_academy(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/access')
            ->assertOk()
            ->assertJsonPath('data.login_enabled', true)
            ->assertJsonPath('data.registration_enabled', true);
    }

    public function test_teacher_can_toggle_access_and_it_persists(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/access', [
            'login_enabled' => false,
            'registration_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.login_enabled', false)
            ->assertJsonPath('data.registration_enabled', false);

        // A partial update toggles one flag without clobbering the other.
        $this->withHeaders($this->h)->putJson('/api/v1/teacher/access', [
            'registration_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('data.login_enabled', false)
            ->assertJsonPath('data.registration_enabled', true);

        $this->assertDatabaseHas('teacher_profiles', [
            'tenant_id' => $this->tenant->id,
            'login_enabled' => false,
            'registration_enabled' => true,
        ]);
    }

    public function test_student_cannot_manage_access(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/access')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unauthenticated_cannot_manage_access(): void
    {
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/access')->assertStatus(401);
    }

    public function test_public_context_reflects_the_switches(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->putJson('/api/v1/teacher/access', ['login_enabled' => false])->assertOk();

        // Public boot payload (no auth) — the SPA reads this to hide the forms.
        $this->withHeaders($this->h)->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.auth.login_enabled', false)
            ->assertJsonPath('data.auth.registration_enabled', true);
    }
}
