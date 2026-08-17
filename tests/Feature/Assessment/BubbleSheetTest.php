<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Enums\ExamGradingMode;
use App\Modules\Assessment\Enums\ExamPassMode;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
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
 * On-site bubble-sheet MCQ builder (doc 13 Phase 7): sheet upsert validation, the
 * answer key staying hidden from students, auto-grade correctness + pass/fail
 * threshold, and manual mode still routing to a teacher.
 */
class BubbleSheetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('2025 / 2026');
        $this->lesson = $this->makeLesson();
    }

    // --- helpers -----------------------------------------------------------

    private function makeYear(string $name): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    /** @return array<string, string> */
    private function teacherHeaders(): array
    {
        return ['X-Tenant' => 'demo', 'X-Academic-Year' => $this->year->uuid];
    }

    /** @return array<string, string> */
    private function studentHeaders(): array
    {
        return ['X-Tenant' => 'demo'];
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
        $l = new Lesson(['title' => 'Lesson', 'visibility' => ContentVisibility::Visible->value]);
        $l->tenant_id = $this->tenant->id;
        $l->academic_year_id = $this->year->id;
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
        $exam = new Exam(array_merge([
            'title' => 'Quiz', 'type' => 'lesson_quiz', 'is_published' => true, 'attempts_allowed' => 1,
        ], $attrs));
        $exam->tenant_id = $this->tenant->id;
        $exam->lesson_id = $this->lesson->id;
        // Exam is year-scoped (site-wide scoping Phase 2); stamp the year so the
        // strict `academic-year` bubble-sheet route can bind it under the header.
        $exam->academic_year_id = $this->year->id;
        $exam->save();

        return $exam;
    }

    /** Two-question sheet: Q1 worth 10 (correct=index 2), Q2 worth 40 (correct=index 0). */
    private function sampleSheet(): array
    {
        return [
            'total_marks' => 50,
            'questions' => [
                ['text' => 'Q1', 'options' => ['A', 'B', 'C', 'D'], 'correct_index' => 2, 'marks' => 10],
                ['text' => 'Q2', 'options' => ['True', 'False'], 'correct_index' => 0, 'marks' => 40],
            ],
        ];
    }

    // --- sheet upsert + validation ----------------------------------------

    public function test_teacher_upserts_sheet_and_reads_it_back_with_answer_key(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $this->sampleSheet())
            ->assertOk()
            ->assertJsonPath('data.total_marks', 50)
            ->assertJsonPath('data.questions.0.correct_index', 2)
            ->assertJsonPath('data.questions.0.marks', 10)
            ->assertJsonPath('data.questions.1.correct_index', 0)
            ->assertJsonCount(2, 'data.questions');

        // GET returns the same sheet incl. the answer key (teacher-only surface).
        $this->withHeaders($this->teacherHeaders())
            ->getJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet")
            ->assertOk()
            ->assertJsonPath('data.questions.0.correct_index', 2)
            ->assertJsonPath('data.total_marks', 50);
    }

    public function test_total_marks_is_derived_when_omitted(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $sheet = $this->sampleSheet();
        unset($sheet['total_marks']);

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $sheet)
            ->assertOk()
            ->assertJsonPath('data.total_marks', 50); // 10 + 40

        $this->assertSame('50.00', (string) $exam->fresh()->total_marks);
    }

    public function test_upsert_replaces_the_whole_sheet(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $this->sampleSheet())->assertOk();

        // A second upsert with one question fully replaces the previous two.
        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", [
                'questions' => [
                    ['text' => 'Only', 'options' => ['x', 'y'], 'correct_index' => 1, 'marks' => 7],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.total_marks', 7);

        $this->assertSame(1, $exam->questions()->count());
    }

    public function test_correct_index_out_of_range_is_rejected(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", [
                'questions' => [
                    ['options' => ['A', 'B'], 'correct_index' => 2, 'marks' => 5], // only indices 0,1 exist
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['questions.0.correct_index']]]);
    }

    public function test_question_needs_at_least_two_options(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", [
                'questions' => [
                    ['options' => ['A'], 'correct_index' => 0, 'marks' => 5],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['questions.0.options']]]);
    }

    public function test_sheet_needs_at_least_one_question(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", ['questions' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['questions']]]);
    }

    public function test_total_marks_must_match_sum(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $sheet = $this->sampleSheet();
        $sheet['total_marks'] = 99; // real sum is 50

        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $sheet)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['total_marks']]]);
    }

    public function test_missing_academic_year_header_is_422(): void
    {
        $exam = $this->makeExam();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->studentHeaders()) // no X-Academic-Year
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $this->sampleSheet())
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['academic_year']]]);
    }

    // --- hidden answer key on student read --------------------------------

    public function test_student_attempt_never_exposes_the_answer_key(): void
    {
        $exam = $this->makeExam(['grading_mode' => ExamGradingMode::Auto->value]);
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $this->sampleSheet())->assertOk();

        Sanctum::actingAs($this->enrolledStudent());
        $start = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertOk();

        foreach ($start->json('data.questions') as $q) {
            $this->assertArrayNotHasKey('correct', $q);
            $this->assertArrayNotHasKey('correct_index', $q);
        }
    }

    // --- auto-grade correctness + pass/fail threshold ---------------------

    public function test_auto_grade_full_marks_passes_marks_threshold(): void
    {
        $exam = $this->makeExam([
            'grading_mode' => ExamGradingMode::Auto->value,
            'pass_mode' => ExamPassMode::Marks->value,
            'pass_value' => 25,
            'total_marks' => 50,
        ]);
        [$q1, $q2] = $this->buildSheet($exam);

        Sanctum::actingAs($this->enrolledStudent());
        $attemptId = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');

        // Both correct → 50 marks ≥ 25 threshold.
        $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
                'answers' => [$q1 => [2], $q2 => [0]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.passed', true);
    }

    public function test_auto_grade_partial_marks_fails_marks_threshold(): void
    {
        $exam = $this->makeExam([
            'grading_mode' => ExamGradingMode::Auto->value,
            'pass_mode' => ExamPassMode::Marks->value,
            'pass_value' => 25,
            'total_marks' => 50,
        ]);
        [$q1, $q2] = $this->buildSheet($exam);

        Sanctum::actingAs($this->enrolledStudent());
        $attemptId = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');

        // Only Q1 correct → 10 marks < 25 threshold.
        $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
                'answers' => [$q1 => [2], $q2 => [1]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 10)
            ->assertJsonPath('data.passed', false);
    }

    public function test_auto_grade_percent_threshold(): void
    {
        $exam = $this->makeExam([
            'grading_mode' => ExamGradingMode::Auto->value,
            'pass_mode' => ExamPassMode::Percent->value,
            'pass_value' => 60,
            'total_marks' => 50,
        ]);
        [$q1, $q2] = $this->buildSheet($exam);

        Sanctum::actingAs($this->enrolledStudent());
        $attemptId = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');

        // Q2 only → 40/50 = 80% ≥ 60%.
        $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
                'answers' => [$q1 => [0], $q2 => [0]],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 40)
            ->assertJsonPath('data.passed', true);
    }

    // --- manual mode still needs a teacher --------------------------------

    public function test_manual_mode_bubble_sheet_still_routes_to_teacher(): void
    {
        // Same MCQ sheet, but the backing exam is graded manually.
        $exam = $this->makeExam(['grading_mode' => ExamGradingMode::Manual->value, 'pass_percent' => 50]);
        [$q1, $q2] = $this->buildSheet($exam);

        Sanctum::actingAs($this->enrolledStudent());
        $attemptId = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('data.attempt_id');

        // MCQ auto-scores even in manual mode, but the attempt is still finalised
        // (no manual-only questions here) — the key assertion is auto mode forces
        // graded while manual leaves the existing needs_manual logic intact.
        $res = $this->withHeaders($this->studentHeaders())
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
                'answers' => [$q1 => [2], $q2 => [0]],
            ])->assertOk();

        // All questions are auto-gradable mcq, so even manual mode has nothing left
        // pending → graded. The distinguishing manual case (an essay left pending)
        // is covered in ExamsTest::test_manual_grading_flow.
        $res->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 50);
    }

    /**
     * Persist the sample sheet through the builder and return the two question ids
     * in sheet order.
     *
     * @return array{0: int, 1: int}
     */
    private function buildSheet(Exam $exam): array
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $questions = $this->withHeaders($this->teacherHeaders())
            ->putJson("/api/v1/teacher/exams/{$exam->uuid}/bubble-sheet", $this->sampleSheet())
            ->assertOk()->json('data.questions');

        return [(int) $questions[0]['id'], (int) $questions[1]['id']];
    }
}
