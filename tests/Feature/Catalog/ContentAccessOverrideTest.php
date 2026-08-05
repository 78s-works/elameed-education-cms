<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\ContentAccessTarget;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Services\ContentAccessOverrideService;
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
 * student open a locked lesson/section, bypassing unmet dependencies. Revoke
 * restores the gate. Grant/revoke are audit-logged.
 *
 * Units are retired (VD §7): lessons are grouped by the dormant `unit_id` column
 * (synthetic ids here). Unit-target overrides can no longer be CREATED via the API
 * (that path 422s), but the service still honours an existing unit override over
 * the dormant column — the last test grants one directly to prove that coverage.
 */
class ContentAccessOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** Hands out synthetic unit-group ids (no units table any more). */
    private int $unitSeq = 0;

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

    /** A synthetic "unit" group id — a value for the dormant lessons.unit_id column. */
    private function unit(): int
    {
        return ++$this->unitSeq;
    }

    private function lesson(Course $course, int $unitId, int $sort): Lesson
    {
        $lesson = new Lesson(['unit_id' => $unitId, 'course_id' => $course->id, 'title' => "L{$sort}", 'sort_order' => $sort]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function exam(int $courseId, array $attrs): Exam
    {
        // array_merge (not +) so $attrs['type'] overrides the default.
        $exam = new Exam(array_merge(
            ['course_id' => $courseId, 'title' => 'X', 'type' => 'lesson_quiz', 'pass_percent' => 50, 'is_published' => true],
            $attrs,
        ));
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
        $unit = $this->unit();
        $l1 = $this->lesson($course, $unit, 0);
        $l2 = $this->lesson($course, $unit, 1);
        $this->enroll($student, $course);

        // L1 carries a published quiz → L2 is locked until it's submitted.
        $this->exam($course->id, ['type' => 'lesson_quiz', 'unit_id' => $unit, 'lesson_id' => $l1->id]);

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

    // ---- section-level override unlocks one gated (solution) section ------------

    public function test_section_override_unlocks_a_solution_locked_section(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit();
        $lesson = $this->lesson($course, $unit, 0);
        $this->enroll($student, $course);

        // A quiz + its solution video — the solution is hidden until the quiz is submitted.
        $this->exam($course->id, ['type' => 'lesson_quiz', 'unit_id' => $unit, 'lesson_id' => $lesson->id]);
        $solution = $this->section($lesson, ['type' => 'quiz_solution', 'media_asset_id' => 1, 'sort_order' => 0]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.0.locked', true);

        // Grant a section override on the gated solution section.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'section', 'target_id' => $solution->id,
            ])
            ->assertCreated();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.0.locked', false);
    }

    // ---- unit-level override still covers lessons under it (dormant column) ------

    public function test_unit_override_covers_a_locked_lesson_in_that_unit(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit();
        $l1 = $this->lesson($course, $unit, 0);
        $l2 = $this->lesson($course, $unit, 1); // second lesson — gated by L1's quiz
        $this->enroll($student, $course);

        // L1 has an unsubmitted quiz → L2 is progression-locked.
        $this->exam($course->id, ['type' => 'lesson_quiz', 'unit_id' => $unit, 'lesson_id' => $l1->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertStatus(423);

        // Unit-target overrides can no longer be created through the API (Unit
        // retired) — but an existing one over the dormant unit_id still opens the
        // unit's lessons. Grant it directly through the service.
        app(ContentAccessOverrideService::class)->grant(
            $this->tenant->id, $student->id, ContentAccessTarget::Unit, $unit, $teacher->id, null,
        );

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertOk();
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
