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

class TeacherCustomLandingTest extends TestCase
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

    public function test_default_is_off_for_a_fresh_academy(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/custom-landing')
            ->assertOk()
            ->assertJsonPath('data.custom_landing_enabled', false);
    }

    public function test_teacher_can_toggle_custom_landing_and_it_persists(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/custom-landing', [
            'custom_landing_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('data.custom_landing_enabled', true);

        $this->assertDatabaseHas('teacher_profiles', [
            'tenant_id' => $this->tenant->id,
            'custom_landing_enabled' => true,
        ]);

        // And back off again.
        $this->withHeaders($this->h)->putJson('/api/v1/teacher/custom-landing', [
            'custom_landing_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.custom_landing_enabled', false);
    }

    public function test_flag_is_required_on_update(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/custom-landing', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.custom_landing_enabled.0', fn ($m) => $m !== null);
    }

    public function test_student_cannot_manage_custom_landing(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/custom-landing')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unauthenticated_cannot_manage_custom_landing(): void
    {
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/custom-landing')->assertStatus(401);
    }

    public function test_public_context_reflects_the_switch(): void
    {
        // Fresh academy → the SPA boots into the CMS landing sections.
        $this->withHeaders($this->h)->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.landing.custom_enabled', false);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->putJson('/api/v1/teacher/custom-landing', ['custom_landing_enabled' => true])->assertOk();

        // Public boot payload (no auth) — the SPA reads this to pick the mode.
        $this->withHeaders($this->h)->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.landing.custom_enabled', true)
            ->assertJsonPath('data.slug', 'demo'); // slug = the custom/<slug>/ folder key
    }
}
