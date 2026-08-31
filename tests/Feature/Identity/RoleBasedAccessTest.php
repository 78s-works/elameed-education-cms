<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * The RBAC invariants (M20). These are the claims the whole design rests on, so
 * they are asserted directly rather than inferred from feature tests:
 *
 *   1. Nobody exists without a role, and the empty roles really are empty.
 *   2. Granting a role opens the matching route with NO code change — the proof
 *      that authority flows through data, not through special cases.
 *   3. Roles never cross academies.
 *   4. A permission change takes effect immediately (no stale cache).
 */
class RoleBasedAccessTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTenantRoles;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);

        $this->year = new AcademicYear(['name' => 'الثالث الثانوي', 'sort_order' => 0]);
        $this->year->tenant_id = $this->tenant->id;
        $this->year->save();
    }

    private function member(TenantUserRole $role, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    // ── 1. Everyone holds a role ────────────────────────────────────────────

    public function test_every_membership_kind_is_given_its_baseline_role(): void
    {
        foreach ([
            [TenantUserRole::Teacher, RoleTemplateKey::Teacher->label()],
            [TenantUserRole::Assistant, RoleTemplateKey::Assistant->label()],
            [TenantUserRole::Student, RoleTemplateKey::Student->label()],
            [TenantUserRole::Parent, RoleTemplateKey::ParentGuardian->label()],
        ] as [$kind, $expected]) {
            $user = $this->member($kind);
            $membership = TenantUser::where('user_id', $user->id)->with('user')->firstOrFail();

            $this->assertSame([$expected], $membership->roleNames(), $kind->value.' baseline role');
        }
    }

    public function test_the_student_role_exists_and_is_empty(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $membership = TenantUser::where('user_id', $student->id)->with('user')->firstOrFail();

        $this->assertSame([RoleTemplateKey::Student->label()], $membership->roleNames());
        $this->assertSame([], $membership->holdsPermissionKeys());
    }

    public function test_a_student_reaches_no_staff_surface(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/teacher/roles')->assertStatus(403);
    }

    // ── 2. The proof: data opens the door, not code ─────────────────────────

    public function test_granting_the_student_role_a_permission_opens_the_route_and_revoking_closes_it(): void
    {
        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        // Closed today — the role is empty.
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);

        // Put ONE key in the student role. No code changes anywhere.
        $studentRole = $this->tenantRole($this->tenant, RoleTemplateKey::Student->value);
        $studentRole->givePermissionTo(PermissionEnum::StudentsView->value);

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertOk();

        // Take it back — and the door shuts again.
        $studentRole->revokePermissionTo(PermissionEnum::StudentsView->value);

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
    }

    public function test_a_permission_change_takes_effect_without_waiting_for_a_cache_to_expire(): void
    {
        $assistant = $this->member(TenantUserRole::Assistant);
        Sanctum::actingAs($assistant);
        $h = ['X-Tenant' => 'demo'];

        // Warm the package's permission cache with a real, denied request.
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);

        $this->grantPermissions($assistant, $this->tenant, [PermissionEnum::CentersView->value]);

        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertOk();
    }

    // ── 3. Isolation ────────────────────────────────────────────────────────

    public function test_roles_do_not_cross_academies(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);

        // Same person, staff in both academies; granted centers in the OTHER one.
        $user = $this->member(TenantUserRole::Assistant);
        TenantUser::create([
            'tenant_id' => $other->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Assistant->value,
            'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
        $this->grantPermissions($user, $other, [PermissionEnum::CentersView->value]);

        Sanctum::actingAs($user);

        // The grant belongs to `other` and must not travel to `demo`.
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/teacher/centers')->assertStatus(403);
        $this->withHeaders(['X-Tenant' => 'other'])->getJson('/api/v1/teacher/centers')->assertOk();
    }

    public function test_two_academies_can_hold_same_named_roles_independently(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);

        $mine = Role::query()->where('tenant_id', $this->tenant->id)->where('name', RoleTemplateKey::Finance->label())->firstOrFail();
        $theirs = Role::query()->where('tenant_id', $other->id)->where('name', RoleTemplateKey::Finance->label())->firstOrFail();

        $this->assertNotSame($mine->getKey(), $theirs->getKey());

        // Widening one copy leaves the other untouched.
        $mine->givePermissionTo(PermissionEnum::StudentsDelete->value);

        $this->assertContains(PermissionEnum::StudentsDelete->value, $mine->permissions()->pluck('name')->all());
        $this->assertNotContains(PermissionEnum::StudentsDelete->value, $theirs->permissions()->pluck('name')->all());
    }

    public function test_deleting_an_academy_takes_its_roles_and_assignments_with_it(): void
    {
        $doomed = Tenant::create(['slug' => 'doomed', 'name' => 'Doomed', 'status' => TenantStatus::Active]);
        $this->member(TenantUserRole::Assistant, $doomed);

        $this->assertGreaterThan(0, DB::table('roles')->where('tenant_id', $doomed->id)->count());
        $this->assertGreaterThan(0, DB::table('model_has_roles')->where('tenant_id', $doomed->id)->count());

        $doomed->forceDelete();

        $this->assertSame(0, DB::table('roles')->where('tenant_id', $doomed->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('tenant_id', $doomed->id)->count());
    }

    // ── 4. Lifecycle ────────────────────────────────────────────────────────

    public function test_changing_a_membership_kind_swaps_the_baseline_role(): void
    {
        $user = $this->member(TenantUserRole::Student);
        $membership = TenantUser::where('user_id', $user->id)->with('user')->firstOrFail();

        $membership->update(['role' => TenantUserRole::Assistant->value]);

        $this->assertSame([RoleTemplateKey::Assistant->label()], $membership->fresh()->load('user')->roleNames());
    }

    public function test_removing_a_membership_removes_every_role_in_that_academy(): void
    {
        $user = $this->member(TenantUserRole::Assistant);
        $this->grantPermissions($user, $this->tenant, [PermissionEnum::CentersView->value]);

        $membership = TenantUser::where('user_id', $user->id)->firstOrFail();
        $membership->delete();

        $this->assertSame(0, DB::table('model_has_roles')
            ->where('tenant_id', $this->tenant->id)
            ->where('model_id', $user->id)
            ->count());
    }

    public function test_a_new_academy_is_stamped_with_locked_system_roles_and_editable_copies(): void
    {
        $fresh = Tenant::create(['slug' => 'fresh', 'name' => 'Fresh', 'status' => TenantStatus::Active]);

        $roles = Role::query()->where('tenant_id', $fresh->id)->get();

        $this->assertCount(count(RoleTemplateKey::cases()), $roles);
        $this->assertCount(4, $roles->where('is_system', true));

        $owner = $roles->firstWhere('template_key', RoleTemplateKey::Teacher->value);
        $student = $roles->firstWhere('template_key', RoleTemplateKey::Student->value);

        $this->assertCount(count(PermissionEnum::cases()), $owner->permissions);
        $this->assertCount(0, $student->permissions);
    }
}
