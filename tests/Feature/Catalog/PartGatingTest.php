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
use App\Modules\Catalog\Models\PartPassOverride;
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
 * Per-part sequential gate (VD change set §7, LP-13/LP-14): a quiz/homework part's
 * `gate_rule` (must_submit / must_pass) locks every later part until the student
 * clears it; `max_tries` caps retakes on the backing exam; a teacher pass-override
 * satisfies a must_pass gate. Legacy solution gating stays in LessonProgressionTest.
 */
class PartGatingTest extends TestCase
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

    private function lesson(Course $course): Lesson
    {
        $lesson = new Lesson(['course_id' => $course->id, 'title' => 'L', 'sort_order' => 0]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    /** @param array<string, mixed> $attrs */
    private function exam(Lesson $lesson, array $attrs = []): Exam
    {
        $exam = new Exam(array_merge([
            'title' => 'X', 'course_id' => $lesson->course_id, 'lesson_id' => $lesson->id,
            'type' => ExamType::LessonQuiz->value, 'pass_percent' => 60,
            'pass_mode' => 'percent', 'pass_value' => 60, 'is_published' => true,
        ], $attrs));
        $exam->tenant_id = $this->tenant->id;
        $exam->save();

        return $exam;
    }

    /** @param array<string, mixed> $attrs */
    private function section(Lesson $lesson, array $attrs): LessonSection
    {
        $section = new LessonSection(['lesson_id' => $lesson->id] + $attrs);
        $section->tenant_id = $this->tenant->id;
        $section->save();

        return $section;
    }

    private function attempt(Exam $exam, User $user, int $score, int $max): ExamAttempt
    {
        $attempt = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $user->id, 'status' => 'submitted',
            'submitted_at' => now(), 'score' => $score, 'max_score' => $max,
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

    /** The `locked` flag on the video part that follows the gating part. */
    private function videoLocked(TestResponse $res): ?bool
    {
        $video = collect($res->json('data'))->firstWhere('type', 'video');

        return $video['locked'] ?? null;
    }

    // ---- must_submit ------------------------------------------------------------

    public function test_must_submit_gate_locks_later_part_until_submitted(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        $exam = $this->exam($lesson, ['type' => ExamType::Homework->value]);
        $this->section($lesson, ['type' => 'homework', 'exam_id' => $exam->id, 'gate_rule' => 'must_submit', 'sort_order' => 0]);
        $this->section($lesson, ['type' => 'video', 'media_asset_id' => 999, 'sort_order' => 1]);

        // Nothing submitted → the video part is locked and its media is withheld.
        $res = $this->sections($student, $lesson)->assertOk();
        $this->assertTrue($this->videoLocked($res));
        $this->assertNull(collect($res->json('data'))->firstWhere('type', 'video')['media_asset_id']);

        // A submitted attempt (any score) clears a must_submit gate.
        $this->attempt($exam, $student, 1, 10);
        $res = $this->sections($student, $lesson)->assertOk();
        $this->assertFalse($this->videoLocked($res));
        $this->assertSame(999, collect($res->json('data'))->firstWhere('type', 'video')['media_asset_id']);
    }

    // ---- must_pass --------------------------------------------------------------

    public function test_must_pass_gate_stays_locked_until_a_passing_attempt(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        $exam = $this->exam($lesson); // pass_mode=percent, pass_value=60
        $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id, 'gate_rule' => 'must_pass', 'sort_order' => 0]);
        $this->section($lesson, ['type' => 'video', 'media_asset_id' => 999, 'sort_order' => 1]);

        // No attempt → locked.
        $this->assertTrue($this->videoLocked($this->sections($student, $lesson)));

        // A failing attempt (50% < 60%) → still locked (submitting is not enough).
        $this->attempt($exam, $student, 5, 10);
        $this->assertTrue($this->videoLocked($this->sections($student, $lesson)));

        // A passing attempt (70% ≥ 60%) → best-of-tries clears the gate.
        $this->attempt($exam, $student, 7, 10);
        $this->assertFalse($this->videoLocked($this->sections($student, $lesson)));
    }

    public function test_teacher_pass_override_satisfies_must_pass_gate(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $teacher = $this->member(TenantUserRole::Teacher);
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        $exam = $this->exam($lesson);
        $gate = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id, 'gate_rule' => 'must_pass', 'sort_order' => 0]);
        $this->section($lesson, ['type' => 'video', 'media_asset_id' => 999, 'sort_order' => 1]);

        // Failing attempt only → still locked.
        $this->attempt($exam, $student, 5, 10);
        $this->assertTrue($this->videoLocked($this->sections($student, $lesson)));

        // Teacher marks the student as passed → gate opens without a passing score.
        $override = new PartPassOverride([
            'lesson_section_id' => $gate->id, 'user_id' => $student->id,
            'granted_by' => $teacher->id, 'granted_at' => now(),
        ]);
        $override->tenant_id = $this->tenant->id;
        $override->save();
        $this->assertFalse($this->videoLocked($this->sections($student, $lesson)));
    }

    // ---- max_tries --------------------------------------------------------------

    public function test_max_tries_caps_retakes_on_the_backing_exam(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        // Part caps at 1 try even though the exam itself allows unlimited (0).
        $exam = $this->exam($lesson, ['attempts_allowed' => 0]);
        $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id, 'gate_rule' => 'must_submit', 'max_tries' => 1, 'sort_order' => 0]);

        // One try already spent → a fresh start is refused.
        $this->attempt($exam, $student, 5, 10);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertStatus(409)
            ->assertSee('No attempts remaining');
    }

    public function test_unlimited_max_tries_allows_another_attempt(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        // Part max_tries null (unlimited) overrides the exam's own cap of 1.
        $exam = $this->exam($lesson, ['attempts_allowed' => 1]);
        $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id, 'gate_rule' => 'must_submit', 'max_tries' => null, 'sort_order' => 0]);

        $this->attempt($exam, $student, 5, 10);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertOk();
    }
}
