<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\ContentDependency;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
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
 * Gap 1 — manual teacher/assistant access overrides: a staff grant lets one
 * student open a locked lesson/section/unit, bypassing unmet dependencies. Revoke
 * restores the gate. Grant/revoke are audit-logged.
 */
class ContentAccessOverrideTest extends TestCase
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
        $exam = new Exam(['course_id' => $courseId, 'title' => 'X', 'type' => 'assignment', 'pass_percent' => 50, 'is_published' => true] + $attrs);
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

    private function enroll(User $student, Course $course): void
    {
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);
    }

    // ---- lesson-level override bypasses the progression gate --------------------

    public function test_lesson_override_opens_a_progression_locked_lesson(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        // L1 carries a required upload homework → L2 is locked until it's graded.
        $hw = $this->exam($course->id, ['type' => 'assignment']);
        $this->section($l1, ['type' => 'assignment', 'assignment_kind' => 'upload', 'is_required' => true, 'exam_id' => $hw->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertStatus(423);

        // Teacher grants an override on L2 for this student.
        Sanctum::actingAs($teacher);
        $overrideId = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'lesson', 'target_id' => $l2->id, 'note' => 'catch-up',
            ])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'lesson')
            ->assertJsonPath('data.active', true)
            ->json('data.id');

        $this->assertDatabaseHas('content_access_overrides', [
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'lesson_id' => $l2->id, 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content_access.override_granted', 'tenant_id' => $this->tenant->id]);

        // Student can now open L2.
        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertOk();

        // Teacher revokes → L2 is locked again.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->deleteJson("/api/v1/teacher/students/{$student->uuid}/content-overrides/{$overrideId}")
            ->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'content_access.override_revoked', 'tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertStatus(423);
    }

    // ---- section-level override unlocks one gated section -----------------------

    public function test_section_override_unlocks_a_dependency_locked_section(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $lesson = $this->lesson($unit, 0);
        $this->enroll($student, $course);

        $quiz = $this->exam($course->id, ['type' => 'exam']);
        $entry = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $quiz->id, 'sort_order' => 0]);
        $body = $this->section($lesson, ['type' => 'pdf', 'pdf_kind' => 'lecture_notes', 'media_asset_id' => 1, 'sort_order' => 1]);
        // Body depends on the entry quiz being submitted (mandatory).
        $dep = new ContentDependency([
            'section_id' => $body->id, 'depends_on_section_id' => $entry->id,
            'trigger' => 'submitted', 'enforcement' => 'mandatory',
        ]);
        $dep->tenant_id = $this->tenant->id;
        $dep->save();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.1.locked', true);

        // Grant a section override on the gated body section.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'section', 'target_id' => $body->id,
            ])
            ->assertCreated();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.1.locked', false);
    }

    // ---- unit-level override covers every lesson under it -----------------------

    public function test_unit_override_covers_a_locked_lesson_in_that_unit(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $this->lesson($u1, 0);
        $l2a = $this->lesson($u2, 0);
        $this->enroll($student, $course);

        // Previous unit has a published exam → first lesson of u2 is gated by default.
        $this->exam($course->id, ['type' => 'exam', 'unit_id' => $u1->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2a->id}/sections")->assertStatus(423);

        // A unit override on u2 opens its lessons.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'unit', 'target_id' => $u2->id,
            ])
            ->assertCreated();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2a->id}/sections")->assertOk();
    }

    // ---- guards -----------------------------------------------------------------

    public function test_override_target_must_exist_in_tenant(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'lesson', 'target_id' => 999999,
            ])
            ->assertStatus(422);
    }
}
