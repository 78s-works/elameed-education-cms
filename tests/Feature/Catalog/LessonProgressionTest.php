<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Models\ExamTimeExtension;
use App\Modules\Catalog\Enums\ContentVisibility;
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
 * doc 11 — lesson progression & gating: unit exams (R2), the cross-lesson /
 * cross-unit "cannot open the next lesson" gates (R5.2 homework, R5.3 unit exam),
 * the per-part compulsory flag (R9), per-lesson access grants (R7), and exam/quiz
 * time extensions (R6).
 */
class LessonProgressionTest extends TestCase
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

    private function enroll(User $student, Course $course): void
    {
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);
    }

    // ---- R5.2 — previous lesson's required homework must be graded --------------

    public function test_next_lesson_locked_until_previous_homework_graded(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        $hw = $this->exam($course->id, ['type' => 'assignment']);
        $this->section($l1, ['type' => 'assignment', 'assignment_kind' => 'upload', 'is_required' => true, 'exam_id' => $hw->id]);

        Sanctum::actingAs($student);

        // No submission yet → L2 is locked (423).
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")
            ->assertStatus(423);

        // Submitted but not graded → still locked.
        $a = $this->attempt($hw, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")
            ->assertStatus(423);

        // Graded (corrected) → L2 opens.
        $a->update(['status' => 'graded']);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")
            ->assertOk();
    }

    public function test_optional_homework_does_not_gate_next_lesson(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        $hw = $this->exam($course->id, ['type' => 'assignment']);
        // is_required = false → never blocks (R9).
        $this->section($l1, ['type' => 'assignment', 'assignment_kind' => 'upload', 'is_required' => false, 'exam_id' => $hw->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2->id}/sections")->assertOk();
    }

    // ---- R5.3 — first lesson of a unit waits on the previous unit's exam --------

    public function test_first_lesson_of_unit_locked_until_previous_unit_exam_answered(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $this->lesson($u1, 0);              // u1 has a lesson
        $l2a = $this->lesson($u2, 0);       // first lesson of u2
        $this->enroll($student, $course);

        $unitExam = $this->exam($course->id, ['type' => 'exam', 'unit_id' => $u1->id]);

        Sanctum::actingAs($student);

        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2a->id}/sections")
            ->assertStatus(423);

        $this->attempt($unitExam, $student, 'submitted');
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$l2a->id}/sections")
            ->assertOk();
    }

    // ---- R2 — unit optional exam CRUD ------------------------------------------

    public function test_teacher_creates_one_exam_per_unit(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $course = $this->course();
        $unit = $this->unit($course, 0);

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$unit->id}/exam", ['title' => 'Unit test', 'type' => 'exam'])
            ->assertCreated();

        $this->assertDatabaseHas('exams', ['unit_id' => $unit->id, 'title' => 'Unit test']);

        // A unit has at most one exam.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/units/{$unit->id}/exam", ['title' => 'Second', 'type' => 'exam'])
            ->assertStatus(409);
    }

    // ---- R7 — teacher grants access to a single lesson --------------------------

    public function test_teacher_grants_lesson_access_and_opens_window(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $lesson = $this->lesson($unit, 0);
        $lesson->update(['availability_days' => 7]);

        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/enrollments", [
                'target_type' => 'lesson', 'target' => (string) $lesson->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'lesson');

        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'lesson_id' => $lesson->id]);
        // Window opened on grant (doc 11 R4/D3).
        $this->assertDatabaseHas('lesson_access_windows', ['lesson_id' => $lesson->id, 'user_id' => $student->id]);
    }

    // ---- R6 — exam/quiz time extension -----------------------------------------

    public function test_exam_time_extension_adds_minutes_to_duration(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $this->enroll($student, $course);
        $exam = $this->exam($course->id, ['type' => 'exam', 'duration_min' => 30, 'max_time_extensions' => 1]);

        // Student requests time.
        Sanctum::actingAs($student);
        $reqId = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/extension-request", ['minutes' => 15])
            ->assertCreated()->json('data.id');

        // Teacher grants 15 minutes.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/exam-extension-requests/{$reqId}/grant", ['minutes' => 15])
            ->assertOk()->assertJsonPath('data.status', 'granted');

        // Start now reports 45 minutes for this student.
        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertOk()
            ->assertJsonPath('data.duration_min', 45);
    }
}
