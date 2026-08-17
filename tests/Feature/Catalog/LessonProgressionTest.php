<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
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
 * In-lesson gating that survives the courses/units retirement (VD §7): solution
 * videos hidden until the matching submission, exams never locked, free exams
 * open to any student, exam↔lesson linkage, single-lesson teacher grants, and
 * exam time extensions.
 *
 * The old unit-based cross-lesson progression gate (and unit_exam) are retired
 * along with the `unit_id` column, so those scenarios were removed.
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

    private function lesson(int $sort = 0): Lesson
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->first();
        if ($year === null) {
            $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
        }

        $lesson = new Lesson(['title' => "L{$sort}", 'sort_order' => $sort, 'price_minor' => 10000]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        return $lesson->fresh();
    }

    /** A published exam bound to a lesson. */
    private function lessonExam(Lesson $lesson, ExamType $type): Exam
    {
        return $this->exam(['lesson_id' => $lesson->id, 'type' => $type->value]);
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

    private function enroll(User $student, Lesson $lesson): void
    {
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);
    }

    private function sections(User $student, Lesson $lesson): TestResponse
    {
        Sanctum::actingAs($student);

        return $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections");
    }

    // ---- solution videos hidden until the matching submission -------------------

    public function test_quiz_solution_video_hidden_until_quiz_submitted(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $l1 = $this->lesson();
        $this->enroll($student, $l1);

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
        $lesson = $this->lesson();
        $this->enroll($student, $lesson);

        $quiz = $this->lessonExam($lesson, ExamType::LessonQuiz);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$quiz->uuid}/attempts")
            ->assertOk();
    }

    public function test_free_exam_is_open_to_any_logged_in_student_without_enrollment(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $freeExam = $this->exam(['type' => ExamType::FreeExam->value]); // no lesson, no enrollment

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$freeExam->uuid}/attempts")
            ->assertOk();
    }

    // ---- teacher exam authoring (top-level, type-driven) ------------------------

    public function test_lesson_quiz_links_to_its_lesson(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/teacher/exams', ['title' => 'Quiz', 'type' => 'lesson_quiz', 'lesson_id' => $lesson->id])
            ->assertCreated()
            ->assertJsonPath('data.lesson_id', $lesson->id);
    }

    // ---- R7 — teacher grants access to a single lesson --------------------------

    public function test_teacher_grants_lesson_access_and_opens_window(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
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
