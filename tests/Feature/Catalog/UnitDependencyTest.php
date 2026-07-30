<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Models\UnitDependency;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gap 2 — configurable, non-sequential unit prerequisites. A unit can be gated
 * behind ANY unit's exam or a specific section (not only the immediately previous
 * unit). With no rule configured the previous-unit-exam default still applies.
 */
class UnitDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function course(): Course
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        return $course;
    }

    private function unit(Course $course, int $sort): Unit
    {
        $unit = new Unit(['course_id' => $course->id, 'title' => "U{$sort}", 'sort_order' => $sort]);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        return $unit;
    }

    private function lesson(Unit $unit, int $sort): Lesson
    {
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $unit->course_id, 'title' => "L{$sort}", 'sort_order' => $sort]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function exam(int $courseId, array $attrs): Exam
    {
        $exam = new Exam(['course_id' => $courseId, 'title' => 'X', 'type' => 'exam', 'pass_percent' => 50, 'is_published' => true] + $attrs);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();

        return $exam;
    }

    private function section(Lesson $lesson, array $attrs): LessonSection
    {
        $section = new LessonSection(['lesson_id' => $lesson->id] + $attrs);
        $section->tenant_id = $this->tenant->id;
        $section->save();

        return $section;
    }

    private function attempt(Exam $exam, User $user, string $status): ExamAttempt
    {
        $attempt = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $user->id, 'status' => $status,
            'submitted_at' => now(), 'score' => 8, 'max_score' => 10,
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        return $attempt;
    }

    private function unitDep(Unit $unit, array $attrs): UnitDependency
    {
        $dep = new UnitDependency(['unit_id' => $unit->id, 'trigger' => 'submitted', 'enforcement' => 'mandatory'] + $attrs);
        $dep->tenant_id = $this->tenant->id;
        $dep->save();

        return $dep;
    }

    private function enroll(User $student, Course $course): void
    {
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);
    }

    // ---- gate on a NON-previous unit's exam ------------------------------------

    public function test_unit_gated_by_a_configured_non_previous_unit(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $u3 = $this->unit($course, 2);
        $this->lesson($u1, 0);
        $l3 = $this->lesson($u3, 0);            // first lesson of u3
        $this->enroll($student, $course);

        $e1 = $this->exam($course->id, ['unit_id' => $u1->id]);
        $e2 = $this->exam($course->id, ['unit_id' => $u2->id]);

        // Configure: u3 depends on u1 (NOT the immediately-previous u2).
        $this->unitDep($u3, ['depends_on_unit_id' => $u1->id, 'trigger' => 'submitted']);

        Sanctum::actingAs($student);

        // Nothing answered → locked.
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l3->id}/sections")->assertStatus(423);

        // Answering the PREVIOUS unit's exam does NOT satisfy the configured dep.
        $this->attempt($e2, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l3->id}/sections")->assertStatus(423);

        // Answering the CONFIGURED prerequisite unit's exam opens it.
        $this->attempt($e1, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l3->id}/sections")->assertOk();
    }

    // ---- gate on a specific section inside another unit ------------------------

    public function test_unit_gated_by_a_specific_prerequisite_section(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $l1 = $this->lesson($u1, 0);
        $l2 = $this->lesson($u2, 0);
        $this->enroll($student, $course);

        $quiz = $this->exam($course->id, ['unit_id' => null]);
        $s = $this->section($l1, ['type' => 'quiz', 'exam_id' => $quiz->id]);

        $this->unitDep($u2, ['depends_on_section_id' => $s->id, 'trigger' => 'submitted']);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertStatus(423);

        $this->attempt($quiz, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertOk();
    }

    // ---- no config → previous-unit-exam default still applies ------------------

    public function test_no_config_falls_back_to_previous_unit_default(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $this->lesson($u1, 0);
        $l2 = $this->lesson($u2, 0);
        $this->enroll($student, $course);

        $prevExam = $this->exam($course->id, ['unit_id' => $u1->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertStatus(423);

        $this->attempt($prevExam, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertOk();
    }

    // ---- teacher CRUD + validation ---------------------------------------------

    public function test_teacher_crud_and_validation(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);

        Sanctum::actingAs($teacher);

        // Create a rule: u2 depends on u1.
        $id = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$u2->id}/dependencies", [
                'depends_on_unit_id' => $u1->id, 'trigger' => 'passed', 'enforcement' => 'mandatory',
            ])
            ->assertCreated()
            ->assertJsonPath('data.depends_on_unit_id', $u1->id)
            ->json('data.id');

        $this->assertDatabaseHas('unit_dependencies', ['unit_id' => $u2->id, 'depends_on_unit_id' => $u1->id]);

        // Both targets at once → 422.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$u2->id}/dependencies", [
                'depends_on_unit_id' => $u1->id, 'depends_on_section_id' => 5, 'trigger' => 'submitted', 'enforcement' => 'mandatory',
            ])
            ->assertStatus(422);

        // Neither target → 422 (required_without).
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$u2->id}/dependencies", [
                'trigger' => 'submitted', 'enforcement' => 'mandatory',
            ])
            ->assertStatus(422);

        // Self-dependency → 422.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$u2->id}/dependencies", [
                'depends_on_unit_id' => $u2->id, 'trigger' => 'submitted', 'enforcement' => 'mandatory',
            ])
            ->assertStatus(422);

        // List + delete.
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/teacher/units/{$u2->id}/dependencies")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withHeader('X-Tenant', 'demo')->deleteJson("/api/v1/teacher/units/{$u2->id}/dependencies/{$id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('unit_dependencies', ['id' => $id]);
    }
}
