<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Models\Question;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\LessonSectionType;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Lesson $lesson;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
        $this->lesson = $this->makeLesson();
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function makeLesson(): Lesson
    {
        $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        $l = new Lesson(['title' => 'Lesson', 'visibility' => ContentVisibility::Visible->value]);
        $l->tenant_id = $this->tenant->id;
        $l->academic_year_id = $year->id;
        $l->save();

        return $l;
    }

    private function enrolledStudent(): User
    {
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $this->lesson, EnrollmentSource::Manual);

        return $student;
    }

    private function makeExam(array $attrs = []): Exam
    {
        // Default to a lesson-scoped lesson_quiz so enrollment still gates access
        // (a free_exam would bypass enrollment and break the access tests).
        $exam = new Exam(array_merge(['title' => 'Quiz', 'type' => 'lesson_quiz', 'is_published' => true, 'pass_percent' => 50, 'attempts_allowed' => 1], $attrs));
        $exam->tenant_id = $this->tenant->id;
        $exam->lesson_id = $this->lesson->id;
        $exam->save();

        return $exam;
    }

    private function makeQuestion(Exam $exam, array $attrs): Question
    {
        $q = new Question($attrs);
        $q->tenant_id = $this->tenant->id;
        $q->exam_id = $exam->id;
        $q->save();

        return $q;
    }

    public function test_teacher_authors_exam_with_a_question(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $examUuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'Midterm', 'type' => 'free_exam', 'pass_percent' => 60])
            ->assertStatus(201)->json('data.uuid');

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/exams/{$examUuid}/questions", [
            'type' => 'mcq', 'body' => '2+2?', 'options' => ['3', '4', '5'], 'correct' => [1], 'points' => 5,
        ])->assertStatus(201)->assertJsonPath('data.correct', [1]); // teacher sees the key

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/exams/{$examUuid}")
            ->assertOk()->assertJsonPath('data.questions_count', 1);
    }

    public function test_student_auto_graded_mcq_and_answer_key_hidden(): void
    {
        $exam = $this->makeExam();
        $q = $this->makeQuestion($exam, ['type' => 'mcq', 'body' => '2+2?', 'options' => ['3', '4', '5'], 'correct' => [1], 'points' => 5]);
        Sanctum::actingAs($this->enrolledStudent());

        $start = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertOk();
        $attemptId = $start->json('data.attempt_id');
        // Answer key must NOT be exposed to the student.
        $this->assertArrayNotHasKey('correct', $start->json('data.questions.0'));

        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
            'answers' => [$q->id => [1]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.passed', true);
    }

    public function test_wrong_answer_scores_zero(): void
    {
        $exam = $this->makeExam();
        $q = $this->makeQuestion($exam, ['type' => 'mcq', 'options' => ['3', '4', '5'], 'correct' => [1], 'points' => 5]);
        Sanctum::actingAs($this->enrolledStudent());

        $attemptId = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');

        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
            'answers' => [$q->id => [0]],
        ])->assertOk()->assertJsonPath('data.score', 0)->assertJsonPath('data.passed', false);
    }

    public function test_attempts_limit_enforced(): void
    {
        $exam = $this->makeExam(['attempts_allowed' => 1]);
        $q = $this->makeQuestion($exam, ['type' => 'mcq', 'options' => ['a', 'b'], 'correct' => [0], 'points' => 1]);
        Sanctum::actingAs($this->enrolledStudent());

        $attemptId = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');
        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", ['answers' => [$q->id => [0]]])->assertOk();

        // Second start → no attempts remaining.
        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertStatus(409);
    }

    public function test_unenrolled_student_cannot_start(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Student)); // not enrolled

        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertStatus(403);
    }

    public function test_manual_grading_flow(): void
    {
        $exam = $this->makeExam(['show_answers' => false]);
        $mcq = $this->makeQuestion($exam, ['type' => 'mcq', 'options' => ['a', 'b'], 'correct' => [0], 'points' => 5]);
        $essay = $this->makeQuestion($exam, ['type' => 'essay', 'body' => 'Discuss.', 'points' => 10]);

        $student = $this->enrolledStudent();
        Sanctum::actingAs($student);
        $attemptId = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');
        $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
            'answers' => [$mcq->id => [0], $essay->id => 'My essay answer.'],
        ])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.needs_manual_grade', true)
            ->assertJsonPath('data.score', 5); // auto part only

        // Teacher grades the essay.
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/exams/{$exam->uuid}/submissions?filter[needs_grading]=1")
            ->assertOk()->assertJsonPath('data.0.attempt_id', $attemptId);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/exams/{$exam->uuid}/attempts/{$attemptId}/grade", [
            'grades' => [$essay->id => 8],
        ])->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 13)          // 5 + 8
            ->assertJsonPath('data.needs_manual_grade', false);
    }

    // ---- Lesson-linked homework ⇄ lesson part sync -----------------------------

    public function test_lesson_linked_homework_mints_a_lesson_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $examUuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', [
                'title' => 'Chapter 1 homework', 'type' => 'homework', 'lesson_id' => $this->lesson->id, 'pass_percent' => 50,
            ])
            ->assertStatus(201)->json('data.uuid');

        $exam = Exam::where('uuid', $examUuid)->firstOrFail();

        // A homework part now backs this exam inside the lesson (point 4 of the spec).
        $section = LessonSection::where('exam_id', $exam->id)->first();
        $this->assertNotNull($section, 'a lesson part should back the lesson-linked homework');
        $this->assertSame(LessonSectionType::Homework, $section->type);
        $this->assertSame($this->lesson->id, $section->lesson_id);
    }

    public function test_standalone_free_exam_does_not_mint_a_lesson_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'Standalone', 'type' => 'free_exam', 'pass_percent' => 50])
            ->assertStatus(201);

        // A standalone (free_exam) links no lesson, so no part is created.
        $this->assertSame(0, LessonSection::count());
    }

    public function test_standalone_free_homework_stays_homework_and_mints_no_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        // Homework with no lesson_id → a "free homework": still type=homework (NOT
        // coerced to free_exam), links no lesson, mints no lesson part.
        $uuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'Free homework', 'type' => 'homework', 'pass_percent' => 50])
            ->assertStatus(201)->json('data.uuid');

        $exam = Exam::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ExamType::Homework, $exam->type);
        $this->assertNull($exam->lesson_id);
        $this->assertSame(0, LessonSection::count());
    }

    public function test_multiple_free_homeworks_are_allowed(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        // No lesson link → the one-per-lesson uniqueness rule must not fire.
        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'HW A', 'type' => 'homework', 'pass_percent' => 50])
            ->assertStatus(201);
        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'HW B', 'type' => 'homework', 'pass_percent' => 50])
            ->assertStatus(201);
    }

    public function test_lesson_quiz_still_requires_a_lesson(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', ['title' => 'Orphan quiz', 'type' => 'lesson_quiz', 'pass_percent' => 50])
            ->assertStatus(422);
    }

    public function test_deleting_lesson_linked_exam_drops_its_lesson_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $examUuid = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/exams', [
                'title' => 'Quiz 1', 'type' => 'lesson_quiz', 'lesson_id' => $this->lesson->id, 'pass_percent' => 50,
            ])
            ->assertStatus(201)->json('data.uuid');

        $this->assertSame(1, LessonSection::count());

        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/exams/{$examUuid}")->assertNoContent();

        $this->assertSame(0, LessonSection::count());
    }

    public function test_cross_tenant_exam_is_404(): void
    {
        $exam = $this->makeExam(); // belongs to demo
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $teacherB = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $other->id, 'user_id' => $teacherB->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($teacherB);
        $this->withHeaders(['X-Tenant' => 'other'])->getJson("/api/v1/teacher/exams/{$exam->uuid}")->assertStatus(404);
    }

    // ---- Gap 3 — richer homework grading (feedback + corrected file) ------------

    public function test_teacher_grades_upload_homework_with_feedback_and_corrected_file(): void
    {
        Storage::fake('local');

        $exam = $this->makeExam(['type' => 'homework']);
        $q = $this->makeQuestion($exam, ['type' => 'file', 'body' => 'Upload your homework.', 'points' => 10]);
        $student = $this->enrolledStudent();

        // A submitted upload-homework attempt awaiting manual grading.
        $attempt = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $student->id, 'attempt_number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'status' => 'submitted',
            'needs_manual_grade' => true, 'max_score' => 10, 'score' => 0,
            'answers' => [$q->id => ['answer' => 'homework.pdf', 'awarded' => null, 'is_correct' => null]],
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        // Teacher grades: points + written feedback + a corrected/annotated file.
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->post("/api/v1/teacher/exams/{$exam->uuid}/attempts/{$attempt->id}/grade", [
            'grades' => [$q->id => 9],
            'feedback' => 'Good work — see the corrected copy.',
            'corrected_file' => UploadedFile::fake()->create('corrected.pdf', 120, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.feedback', 'Good work — see the corrected copy.')
            ->assertJsonPath('data.corrected_file.name', 'corrected.pdf');

        $attempt->refresh();
        $this->assertSame('Good work — see the corrected copy.', $attempt->feedback);
        $this->assertNotNull($attempt->corrected_file['path'] ?? null);
        Storage::disk('local')->assertExists($attempt->corrected_file['path']);

        // Student sees feedback + corrected file on their result, and can download it.
        Sanctum::actingAs($student);
        $this->withHeaders($this->h)->getJson("/api/v1/exams/{$exam->uuid}/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('data.feedback', 'Good work — see the corrected copy.')
            ->assertJsonPath('data.corrected_file.name', 'corrected.pdf');

        $this->withHeaders($this->h)->get("/api/v1/exams/{$exam->uuid}/attempts/{$attempt->id}/corrected-file")
            ->assertOk();
    }

    public function test_grading_without_feedback_keeps_plain_grade_response(): void
    {
        $exam = $this->makeExam(['type' => 'homework']);
        $q = $this->makeQuestion($exam, ['type' => 'essay', 'body' => 'Discuss.', 'points' => 10]);
        $student = $this->enrolledStudent();

        $attempt = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $student->id, 'attempt_number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'status' => 'submitted',
            'needs_manual_grade' => true, 'max_score' => 10, 'score' => 0,
            'answers' => [$q->id => ['answer' => 'essay text', 'awarded' => null, 'is_correct' => null]],
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->postJson("/api/v1/teacher/exams/{$exam->uuid}/attempts/{$attempt->id}/grade", [
            'grades' => [$q->id => 7],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.feedback', null)
            ->assertJsonPath('data.corrected_file', null);

        // No corrected file → student download 404s.
        Sanctum::actingAs($student);
        $this->withHeaders($this->h)->get("/api/v1/exams/{$exam->uuid}/attempts/{$attempt->id}/corrected-file")
            ->assertStatus(404);
    }
}
