<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentPortalTest extends TestCase
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

    private function member(TenantUserRole $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function link(User $parent, User $student): void
    {
        $l = new ParentLink(['parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'relation' => 'father']);
        $l->tenant_id = $this->tenant->id;
        $l->save();
    }

    public function test_teacher_links_a_parent_to_a_student(): void
    {
        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/parents", [
            'name' => 'Abu Sara', 'phone' => '01777000001', 'relation' => 'father',
        ])->assertStatus(201)->assertJsonPath('data.relation', 'father');

        $parent = User::where('phone', '01777000001')->firstOrFail();
        $this->assertDatabaseHas('parent_links', [
            'tenant_id' => $this->tenant->id, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id,
        ]);
        // Parent got a parent membership so they can log in.
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active',
        ]);
    }

    public function test_parent_sees_only_their_own_children(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $mine = $this->member(TenantUserRole::Student, ['name' => 'My Kid']);
        $other = $this->member(TenantUserRole::Student, ['name' => 'Other Kid']);
        $this->link($parent, $mine);

        Sanctum::actingAs($parent);

        $res = $this->withHeaders($this->h)->getJson('/api/v1/parent/children')->assertOk();
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertSame(['My Kid'], $names);

        // Can view own child's progress...
        $this->withHeaders($this->h)->getJson("/api/v1/parent/children/{$mine->uuid}/progress")->assertOk();
        // ...but not a child they aren't linked to.
        $this->withHeaders($this->h)->getJson("/api/v1/parent/children/{$other->uuid}/progress")->assertStatus(404);
    }

    public function test_student_cannot_use_parent_endpoints(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders($this->h)->getJson('/api/v1/parent/children')->assertStatus(403);
    }
}
