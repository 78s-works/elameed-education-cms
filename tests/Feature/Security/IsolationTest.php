<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * The three axes a request is confined to (M20), asserted independently because
 * each one fails differently and each one is a breach on its own:
 *
 *   ROLE   — what the member may do. No role, no access; the role's keys decide.
 *   TENANT — which academy. A grant, a role, and a row all stop at the boundary.
 *   YEAR   — which academic year's content, for a member scoped to years.
 *
 * They are also tested TOGETHER: holding the right permission in the right
 * academy still must not reach another year, and being assigned the right year
 * must not substitute for the permission.
 */
class IsolationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTenantRoles;

    private Tenant $alpha;

    private Tenant $beta;

    private AcademicYear $alphaYear1;

    private AcademicYear $alphaYear2;

    private AcademicYear $betaYear;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->alpha = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'status' => TenantStatus::Active]);
        $this->beta = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'status' => TenantStatus::Active]);

        $this->alphaYear1 = $this->year($this->alpha, 'الأول الثانوي');
        $this->alphaYear2 = $this->year($this->alpha, 'الثاني الثانوي');
        $this->betaYear = $this->year($this->beta, 'الأول الثانوي');
    }

    private function year(Tenant $tenant, string $name): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $tenant->id;
        $year->save();

        return $year;
    }

    /**
     * @param  list<AcademicYear>  $years
     */
    private function member(Tenant $tenant, TenantUserRole $kind, array $years = []): User
    {
        $user = User::factory()->create();

        $membership = TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $kind->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        if ($years !== []) {
            $membership->academicYears()->sync(array_map(fn (AcademicYear $y) => $y->id, $years));
        }

        return $user;
    }

    private function lesson(Tenant $tenant, AcademicYear $year, string $title): Lesson
    {
        $lesson = new Lesson(['title' => $title, 'sort_order' => 0]);
        $lesson->tenant_id = $tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        return $lesson;
    }

    // ── ROLE ────────────────────────────────────────────────────────────────

    public function test_role_isolation_a_member_reaches_only_what_their_roles_grant(): void
    {
        $user = $this->member($this->alpha, TenantUserRole::Assistant);
        $this->grantPermissions($user, $this->alpha, [PermissionEnum::CentersView->value]);
        Sanctum::actingAs($user);

        $h = ['X-Tenant' => 'alpha'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertOk();
        // Neighbouring keys in the SAME group are not implied by one another.
        $this->withHeaders($h)->postJson('/api/v1/teacher/centers', ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/teacher/codes')->assertStatus(403);
        // Nor is anything outside the group.
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
    }

    public function test_role_isolation_two_members_of_one_academy_do_not_share_grants(): void
    {
        $withCenters = $this->member($this->alpha, TenantUserRole::Assistant);
        $withStudents = $this->member($this->alpha, TenantUserRole::Assistant);

        $this->grantPermissions($withCenters, $this->alpha, [PermissionEnum::CentersView->value]);
        $this->grantPermissions($withStudents, $this->alpha, [PermissionEnum::StudentsView->value]);

        $h = ['X-Tenant' => 'alpha'];

        Sanctum::actingAs($withCenters);
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);

        Sanctum::actingAs($withStudents);
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    public function test_role_isolation_revoking_the_role_closes_the_door_at_once(): void
    {
        $user = $this->member($this->alpha, TenantUserRole::Assistant);
        $role = $this->grantPermissions($user, $this->alpha, [PermissionEnum::CentersView->value]);
        Sanctum::actingAs($user);
        $h = ['X-Tenant' => 'alpha'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertOk();

        $this->revokeRole($user, $this->alpha, $role);

        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    // ── TENANT ──────────────────────────────────────────────────────────────

    public function test_tenant_isolation_a_grant_does_not_travel_between_academies(): void
    {
        $user = $this->member($this->alpha, TenantUserRole::Assistant);
        TenantUser::create([
            'tenant_id' => $this->beta->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Assistant->value,
            'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        // Granted in beta only.
        $this->grantPermissions($user, $this->beta, [PermissionEnum::StudentsView->value]);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Tenant' => 'beta'])->getJson('/api/v1/teacher/students')->assertOk();
        $this->withHeaders(['X-Tenant' => 'alpha'])->getJson('/api/v1/teacher/students')->assertStatus(403);
    }

    public function test_tenant_isolation_a_role_uuid_from_another_academy_is_not_found(): void
    {
        $owner = $this->member($this->alpha, TenantUserRole::Teacher);
        $foreign = Role::query()
            ->where('tenant_id', $this->beta->id)
            ->where('template_key', RoleTemplateKey::Finance->value)
            ->firstOrFail();

        Sanctum::actingAs($owner);

        $this->withHeaders(['X-Tenant' => 'alpha'])
            ->putJson("/api/v1/teacher/roles/{$foreign->uuid}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame(RoleTemplateKey::Finance->label(), $foreign->fresh()->name);
    }

    public function test_tenant_isolation_content_of_another_academy_is_invisible(): void
    {
        $mine = $this->lesson($this->alpha, $this->alphaYear1, 'Alpha lesson');
        $theirs = $this->lesson($this->beta, $this->betaYear, 'Beta lesson');

        $owner = $this->member($this->alpha, TenantUserRole::Teacher);
        Sanctum::actingAs($owner);

        $titles = $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->alphaYear1->uuid,
        ])->getJson('/api/v1/teacher/lessons')->assertOk()->json('data.*.title');

        $this->assertContains($mine->title, $titles);
        $this->assertNotContains($theirs->title, $titles);
    }

    public function test_tenant_isolation_another_academys_year_header_is_refused(): void
    {
        $owner = $this->member($this->alpha, TenantUserRole::Teacher);
        Sanctum::actingAs($owner);

        // Beta's year uuid is not resolvable inside alpha — the year lookup is
        // tenant-scoped, so it reads as "no such year", not as another academy's.
        $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->betaYear->uuid,
        ])->getJson('/api/v1/teacher/lessons')->assertStatus(403);
    }

    // ── YEAR ────────────────────────────────────────────────────────────────

    public function test_year_isolation_content_is_scoped_to_the_requested_year(): void
    {
        $first = $this->lesson($this->alpha, $this->alphaYear1, 'First year lesson');
        $second = $this->lesson($this->alpha, $this->alphaYear2, 'Second year lesson');

        Sanctum::actingAs($this->member($this->alpha, TenantUserRole::Teacher));

        $titles = $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->alphaYear1->uuid,
        ])->getJson('/api/v1/teacher/lessons')->assertOk()->json('data.*.title');

        $this->assertContains($first->title, $titles);
        $this->assertNotContains($second->title, $titles);
    }

    public function test_year_isolation_a_member_assigned_one_year_cannot_work_in_another(): void
    {
        // Hired for the second year only, with full content rights.
        $assistant = $this->member($this->alpha, TenantUserRole::Assistant, [$this->alphaYear2]);
        $this->grantPermissions($assistant, $this->alpha, [
            PermissionEnum::ContentView->value,
            PermissionEnum::LessonsCreate->value,
        ]);
        Sanctum::actingAs($assistant);

        $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->alphaYear2->uuid,
        ])->getJson('/api/v1/teacher/lessons')->assertOk();

        // Same permissions, wrong year: scope refuses what authority allows.
        $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->alphaYear1->uuid,
        ])->getJson('/api/v1/teacher/lessons')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'academic_year_out_of_scope');
    }

    public function test_year_isolation_an_unscoped_member_reaches_every_year(): void
    {
        // The owner is assigned no years, which means all of them.
        Sanctum::actingAs($this->member($this->alpha, TenantUserRole::Teacher));

        foreach ([$this->alphaYear1, $this->alphaYear2] as $year) {
            $this->withHeaders([
                'X-Tenant' => 'alpha',
                'X-Academic-Year' => $year->uuid,
            ])->getJson('/api/v1/teacher/lessons')->assertOk();
        }
    }

    public function test_year_scope_is_not_a_substitute_for_a_permission(): void
    {
        // Assigned the year, granted nothing.
        Sanctum::actingAs($this->member($this->alpha, TenantUserRole::Assistant, [$this->alphaYear1]));

        $this->withHeaders([
            'X-Tenant' => 'alpha',
            'X-Academic-Year' => $this->alphaYear1->uuid,
        ])->getJson('/api/v1/teacher/lessons')->assertStatus(403);
    }

    public function test_a_student_is_pinned_to_their_own_year_whatever_the_header_says(): void
    {
        $student = $this->member($this->alpha, TenantUserRole::Student);
        $profile = new StudentProfile(['academic_year_id' => $this->alphaYear1->id]);
        $profile->tenant_id = $this->alpha->id; // no request context in tests
        $profile->user_id = $student->id;
        $profile->save();

        $ownYear = $this->lesson($this->alpha, $this->alphaYear1, 'First year lesson');
        $otherYear = $this->lesson($this->alpha, $this->alphaYear2, 'Second year lesson');

        Sanctum::actingAs($student);

        // The header is not the student's to choose: their year comes from their
        // profile, so a lesson from another year is simply not there.
        $h = ['X-Tenant' => 'alpha', 'X-Academic-Year' => $this->alphaYear2->uuid];

        // Server-side pinning: /me reports the profile's year, not the header's.
        $this->withHeaders($h)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.academic_year.uuid', $this->alphaYear1->uuid);

        // And a lesson from the year they are not in never opens (403/404 both
        // mean "not yours"; what matters is that 200 is impossible).
        $status = $this->withHeaders($h)->getJson("/api/v1/lessons/{$otherYear->id}")->status();
        $this->assertContains($status, [403, 404], "A student reached another year's lesson");

        $this->assertNotNull($ownYear->id);
    }
}
