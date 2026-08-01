<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Convention-gating model — the automatic prev-lesson quiz+homework gate, the
 * always-open first lesson, exams that are never locked, free exams open to any
 * student, and solution videos hidden until the matching submission.
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

    /** A published exam bound to a lesson (auto course/unit from the lesson). */
    private function lessonExam(Lesson $lesson, ExamType $type): Exam
    {
        return $this->exam([
            'course_id' => $lesson->course_id, 'unit_id' => $lesson->unit_id,
            'lesson_id' => $lesson->id, 'type' => $type->value,
        ]);
    }

    private function exam(array $attrs): Exam
    {
        // array_merge (not +) so $attrs['type'] overrides the default.
        $exam = new Exam(array_merge(
            ['title' => 'X', 'type' => ExamType::FreeExam->value, 'pass_percent' => 50, 'is_published' => true],
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

    private function submit(Exam $exam, User $user): ExamAttempt
    {
        $attempt = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $user->id, 'status' => 'submitted',
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

    private function sections(User $student, Lesson $lesson): TestResponse
    {
        Sanctum::actingAs($student);

        return $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections");
    }

    // ---- the automatic prev-lesson gate -----------------------------------------

    public function test_first_lesson_of_unit_is_always_open(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $this->enroll($student, $course);

        // A quiz on the first lesson, unsubmitted, must NOT block the first lesson.
        $this->lessonExam($l1, ExamType::LessonQuiz);

        $this->sections($student, $l1)->assertOk();
    }

    public function test_next_lesson_unlocks_only_after_prev_quiz_and_homework_submitted(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        $quiz = $this->lessonExam($l1, ExamType::LessonQuiz);
        $hw = $this->lessonExam($l1, ExamType::Homework);

        // Nothing submitted → locked on the quiz.
        $this->sections($student, $l2)->assertStatus(423)->assertSee('prev_quiz_missing');

        // Quiz submitted, homework still missing → still locked.
        $this->submit($quiz, $student);
        $this->sections($student, $l2)->assertStatus(423)->assertSee('prev_homework_missing');

        // Both submitted (grading NOT required) → opens.
        $this->submit($hw, $student);
        $this->sections($student, $l2)->assertOk();
    }

    public function test_next_lesson_open_when_previous_lesson_has_no_quiz_or_homework(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        $this->sections($student, $l2)->assertOk();
    }

    public function test_unit_exam_does_not_gate_the_next_unit(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $u1 = $this->unit($course, 0);
        $u2 = $this->unit($course, 1);
        $this->lesson($u1, 0);
        $l2a = $this->lesson($u2, 0); // first lesson of the next unit
        $this->enroll($student, $course);

        // An unanswered unit exam on u1 must NOT lock u2's first lesson (no cross-unit gate).
        $this->exam(['course_id' => $course->id, 'unit_id' => $u1->id, 'type' => ExamType::UnitExam->value]);

        $this->sections($student, $l2a)->assertOk();
    }

    // ---- solution videos hidden until the matching submission -------------------

    public function test_quiz_solution_video_hidden_until_quiz_submitted(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $this->enroll($student, $course);

        $quiz = $this->lessonExam($l1, ExamType::LessonQuiz);
        $this->section($l1, ['type' => 'quiz_solution', 'media_asset_id' => 999, 'is_required' => false]);

        // Before submit: the solution section is locked and its media is withheld.
        $res = $this->sections($student, $l1)->assertOk();
        $sol = collect($res->json('data'))->firstWhere('type', 'quiz_solution');
        $this->assertTrue($sol['locked']);
        $this->assertNull($sol['media_asset_id']);

        // After submitting the quiz: unlocked, media exposed.
        $this->submit($quiz, $student);
        $res = $this->sections($student, $l1)->assertOk();
        $sol = collect($res->json('data'))->firstWhere('type', 'quiz_solution');
        $this->assertFalse($sol['locked']);
        $this->assertSame(999, $sol['media_asset_id']);
    }

    // ---- exams are never locked -------------------------------------------------

    public function test_lesson_quiz_is_startable_any_time(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $l1 = $this->lesson($unit, 0);
        $l2 = $this->lesson($unit, 1);
        $this->enroll($student, $course);

        // L2's quiz is playable even though L2 (the lesson) is progression-locked.
        $quiz = $this->lessonExam($l2, ExamType::LessonQuiz);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$quiz->uuid}/attempts")
            ->assertOk();
    }

    public function test_free_exam_is_open_to_any_logged_in_student_without_enrollment(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $freeExam = $this->exam(['type' => ExamType::FreeExam->value]); // no course, no enrollment

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$freeExam->uuid}/attempts")
            ->assertOk();
    }

    // ---- teacher exam authoring (top-level, type-driven) ------------------------

    public function test_teacher_creates_one_unit_exam_per_unit(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $course = $this->course();
        $unit = $this->unit($course, 0);

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/teacher/exams', ['title' => 'Unit test', 'type' => 'unit_exam', 'unit_id' => $unit->id])
            ->assertCreated();

        // course_id auto-filled from the unit.
        $this->assertDatabaseHas('exams', ['unit_id' => $unit->id, 'course_id' => $course->id, 'type' => 'unit_exam']);

        // A unit has at most one unit_exam.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/teacher/exams', ['title' => 'Second', 'type' => 'unit_exam', 'unit_id' => $unit->id])
            ->assertStatus(409);
    }

    public function test_lesson_quiz_autofills_course_and_unit_from_lesson(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $course = $this->course();
        $unit = $this->unit($course, 0);
        $lesson = $this->lesson($unit, 0);

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/teacher/exams', ['title' => 'Quiz', 'type' => 'lesson_quiz', 'lesson_id' => $lesson->id])
            ->assertCreated()
            ->assertJsonPath('data.unit_id', $unit->id)
            ->assertJsonPath('data.course_id', $course->id);
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
        $this->assertDatabaseHas('lesson_access_windows', ['lesson_id' => $lesson->id, 'user_id' => $student->id]);
    }

    // ---- R6 — exam/quiz time extension -----------------------------------------

    public function test_exam_time_extension_adds_minutes_to_duration(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $exam = $this->exam(['type' => ExamType::FreeExam->value, 'duration_min' => 30, 'max_time_extensions' => 1]);

        Sanctum::actingAs($student);
        $reqId = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/extension-request", ['minutes' => 15])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/exam-extension-requests/{$reqId}/grant", ['minutes' => 15])
            ->assertOk()->assertJsonPath('data.status', 'granted');

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertOk()
            ->assertJsonPath('data.duration_min', 45);
    }
}
