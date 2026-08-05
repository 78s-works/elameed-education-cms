<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Centers\Models\Center;
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

/**
 * Center paper-exam grade entry (VD R12, doc 13 Phase 15).
 */
class CenterExamGradeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    /** Tenant + academic-year headers for the year-scoped teacher routes. */
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = $this->makeTenant('demo');
        $this->year = $this->makeYear($this->tenant, 'Year A');
        $this->h = ['X-Tenant' => 'demo', 'X-Academic-Year' => $this->year->uuid];
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function makeYear(Tenant $tenant, string $name, int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $tenant->id;
        $year->save();

        return $year;
    }

    private function member(Tenant $tenant, TenantUserRole $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function makeCenter(Tenant $tenant, string $name = 'Main Branch'): Center
    {
        $center = new Center(['name' => $name]);
        $center->tenant_id = $tenant->id;
        $center->save();

        return $center;
    }

    private function link(Tenant $tenant, User $parent, User $student): void
    {
        $l = new ParentLink(['parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'relation' => 'father']);
        $l->tenant_id = $tenant->id;
        $l->save();
    }

    private function payload(Center $center, User $student, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Monthly test — Algebra',
            'center' => $center->uuid,
            'student' => $student->uuid,
            'total_marks' => 40,
            'score' => 33.5,
            'sat_on' => '2026-07-15',
        ], $overrides);
    }

    // --- CRUD ---------------------------------------------------------------

    public function test_teacher_can_crud_a_center_exam_grade(): void
    {
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);

        // Create → 201, stamped with the active academic year + author.
        $uuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student))
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Monthly test — Algebra')
            ->assertJsonPath('data.score', 33.5)
            ->assertJsonPath('data.total_marks', 40)
            ->assertJsonPath('data.student.uuid', $student->uuid)
            ->assertJsonPath('data.center.uuid', $center->uuid)
            ->json('data.id');

        $this->assertDatabaseHas('center_exam_grades', [
            'uuid' => $uuid,
            'tenant_id' => $this->tenant->id,
            'academic_year_id' => $this->year->id,
            'student_user_id' => $student->id,
            'center_id' => $center->id,
        ]);

        // List → the new grade.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-exam-grades')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $uuid);

        // Update the score.
        $this->withHeaders($this->h)
            ->putJson("/api/v1/teacher/center-exam-grades/{$uuid}", $this->payload($center, $student, ['score' => 40]))
            ->assertOk()
            ->assertJsonPath('data.score', 40);

        // Delete → gone.
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/center-exam-grades/{$uuid}")->assertNoContent();
        $this->assertDatabaseMissing('center_exam_grades', ['uuid' => $uuid]);
    }

    public function test_index_filters_by_student(): void
    {
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $a = $this->member($this->tenant, TenantUserRole::Student);
        $b = $this->member($this->tenant, TenantUserRole::Student);

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $a))->assertStatus(201);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $b))->assertStatus(201);

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/center-exam-grades?student={$a->uuid}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student.uuid', $a->uuid);
    }

    // --- Validation ---------------------------------------------------------

    public function test_score_cannot_exceed_total_marks(): void
    {
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student, ['total_marks' => 40, 'score' => 41]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        // Negatives rejected too.
        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student, ['score' => -1]))
            ->assertStatus(422);
    }

    public function test_grading_a_non_student_or_foreign_center_is_rejected(): void
    {
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);

        // A teacher is not a valid grade subject.
        $notStudent = $this->member($this->tenant, TenantUserRole::Teacher);
        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student, ['student' => $notStudent->uuid]))
            ->assertStatus(422);

        // A center from another tenant is not resolvable.
        $otherTenant = $this->makeTenant('beta');
        $foreignCenter = $this->makeCenter($otherTenant, 'Beta Branch');
        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student, ['center' => $foreignCenter->uuid]))
            ->assertStatus(422);
    }

    // --- Isolation ----------------------------------------------------------

    public function test_grades_are_year_isolated(): void
    {
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);

        $uuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student))
            ->assertStatus(201)->json('data.id');

        // Same tenant, a different academic year: the grade is invisible and
        // unreachable (bound within the active year → 404).
        $yearB = $this->makeYear($this->tenant, 'Year B', 1);
        $hb = ['X-Tenant' => 'demo', 'X-Academic-Year' => $yearB->uuid];

        $this->withHeaders($hb)->getJson('/api/v1/teacher/center-exam-grades')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->withHeaders($hb)->putJson("/api/v1/teacher/center-exam-grades/{$uuid}", $this->payload($center, $student))->assertStatus(404);
        $this->withHeaders($hb)->deleteJson("/api/v1/teacher/center-exam-grades/{$uuid}")->assertStatus(404);
    }

    public function test_grades_are_tenant_isolated(): void
    {
        // Tenant A owns a grade.
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student))->assertStatus(201);

        // Tenant B's teacher sees nothing of A's.
        $tenantB = $this->makeTenant('beta');
        $yearB = $this->makeYear($tenantB, 'B Year');
        Sanctum::actingAs($this->member($tenantB, TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'beta', 'X-Academic-Year' => $yearB->uuid])
            ->getJson('/api/v1/teacher/center-exam-grades')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // --- Student + parent reads ---------------------------------------------

    public function test_student_sees_only_their_own_grades(): void
    {
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $center = $this->makeCenter($this->tenant);
        $mine = $this->member($this->tenant, TenantUserRole::Student);
        $other = $this->member($this->tenant, TenantUserRole::Student);

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $mine, ['title' => 'Mine']))->assertStatus(201);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $other, ['title' => 'Theirs']))->assertStatus(201);

        // The student reads own grades — no academic-year header needed.
        Sanctum::actingAs($mine);
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me/center-exam-grades')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_parent_sees_linked_childs_grades_in_results(): void
    {
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $center = $this->makeCenter($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $parent = $this->member($this->tenant, TenantUserRole::Parent);
        $this->link($this->tenant, $parent, $student);

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-exam-grades', $this->payload($center, $student, ['title' => 'Paper Final']))->assertStatus(201);

        Sanctum::actingAs($parent);
        $res = $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/parent/children/{$student->uuid}/results")
            ->assertOk()
            ->json('data');

        $center = collect($res)->firstWhere('source', 'center_exam');
        $this->assertNotNull($center);
        $this->assertSame('Paper Final', $center['exam']);
        $this->assertEquals(40, $center['max_score']);
    }
}
