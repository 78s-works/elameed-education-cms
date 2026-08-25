<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\LessonProgress;
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
 * The student page's overview numbers and its two performance feeds
 * (attendance, exam results). Every figure must come from real rows, and must
 * still read correctly when the panel is pinned to a DIFFERENT academic year
 * than the student's own content.
 */
class TeacherStudentOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('Year One');
    }

    private function makeYear(string $name): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function member(TenantUserRole $role, array $userAttrs = []): User
    {
        $user = User::factory()->create($userAttrs);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now()->subDays(60),
        ]);

        return $user;
    }

    private function makeLesson(string $title, ?AcademicYear $year = null): Lesson
    {
        $lesson = new Lesson(['title' => $title, 'visibility' => ContentVisibility::Visible->value]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = ($year ?? $this->year)->id;
        $lesson->save();

        return $lesson;
    }

    private function makeCenter(string $name = 'Maadi'): Center
    {
        $center = new Center(['name' => $name, 'is_active' => true]);
        $center->tenant_id = $this->tenant->id;
        $center->save();

        return $center;
    }

    private function attend(User $student, Center $center, string $status, string $day): void
    {
        $rec = new AttendanceRecord([
            'academic_year_id' => $this->year->id,
            'center_id' => $center->id,
            'user_id' => $student->id,
            'attended_on' => $day,
            'status' => $status,
            'source' => 'center',
        ]);
        $rec->tenant_id = $this->tenant->id;
        $rec->save();
    }

    public function test_summary_reports_completion_attendance_scores_and_devices(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student, ['name' => 'Sara']);

        // Two lessons granted, one finished → 50% completion.
        $a = $this->makeLesson('Chapter one');
        $b = $this->makeLesson('Chapter two');
        $enrollments = app(EnrollmentService::class);
        $enrollments->grantLesson($this->tenant->id, $student->id, $a, EnrollmentSource::Manual);
        $enrollments->grantLesson($this->tenant->id, $student->id, $b, EnrollmentSource::Manual);

        $progress = new LessonProgress([
            'lesson_id' => $a->id,
            'academic_year_id' => $this->year->id,
            'user_id' => $student->id,
            'watch_percent' => 100,
            'completed_at' => now(),
        ]);
        $progress->tenant_id = $this->tenant->id;
        $progress->save();

        // Two of three sessions attended → 67%.
        $center = $this->makeCenter();
        $this->attend($student, $center, 'present', '2026-08-01');
        $this->attend($student, $center, 'present', '2026-08-02');
        $this->attend($student, $center, 'absent', '2026-08-03');

        // One online attempt at 80% and one paper grade at 50% → average 65%.
        $exam = new Exam(['title' => 'Quiz', 'lesson_id' => $a->id, 'academic_year_id' => $this->year->id]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();
        $attempt = new ExamAttempt([
            'academic_year_id' => $this->year->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 1,
            'score' => 8,
            'max_score' => 10,
            'status' => AttemptStatus::Graded->value,
            'submitted_at' => now()->subDay(),
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        $grade = new CenterExamGrade([
            'center_id' => $center->id,
            'student_user_id' => $student->id,
            'title' => 'Paper test',
            'total_marks' => 20,
            'score' => 10,
            'sat_on' => '2026-08-02',
        ]);
        $grade->tenant_id = $this->tenant->id;
        $grade->academic_year_id = $this->year->id;
        $grade->save();

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk();

        $this->assertSame(2, $res->json('data.summary.lessons_granted'));
        $this->assertSame(1, $res->json('data.summary.lessons_completed'));
        $this->assertSame(50, $res->json('data.summary.content_completion_percent'));
        $this->assertSame(2, $res->json('data.summary.attendance_present'));
        $this->assertSame(3, $res->json('data.summary.attendance_total'));
        $this->assertSame(67, $res->json('data.summary.attendance_percent'));
        $this->assertSame(2, $res->json('data.summary.exams_taken'));
        $this->assertSame(65, $res->json('data.summary.average_exam_percent'));
        $this->assertSame(0, $res->json('data.summary.devices_count'));
        $this->assertSame('active', $res->json('data.summary.subscription.status'));
        $this->assertSame(2, $res->json('data.summary.subscription.active_count'));
    }

    public function test_metrics_are_null_when_there_is_nothing_to_measure(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk();

        // Null, not 0 — the panel says "no data" instead of implying a real zero.
        $this->assertNull($res->json('data.summary.content_completion_percent'));
        $this->assertNull($res->json('data.summary.attendance_percent'));
        $this->assertNull($res->json('data.summary.average_exam_percent'));
        $this->assertNull($res->json('data.summary.last_active_at'));
        $this->assertSame('none', $res->json('data.summary.subscription.status'));
    }

    public function test_attendance_feed_lists_rows_with_a_present_summary(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $center = $this->makeCenter('Nasr City');
        $this->attend($student, $center, 'present', '2026-08-01');
        $this->attend($student, $center, 'absent', '2026-08-05');

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}/attendance")
            ->assertOk();

        // Newest first.
        $this->assertSame('2026-08-05', $res->json('data.0.attended_on'));
        $this->assertSame('absent', $res->json('data.0.status'));
        $this->assertSame('Nasr City', $res->json('data.0.center'));
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(1, $res->json('meta.present'));
        $this->assertSame(50, $res->json('meta.percent'));
    }

    public function test_exam_results_merge_online_attempts_and_paper_grades(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->makeLesson('Chapter one');
        $center = $this->makeCenter();

        $exam = new Exam(['title' => 'Online quiz', 'lesson_id' => $lesson->id, 'academic_year_id' => $this->year->id]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();
        $attempt = new ExamAttempt([
            'academic_year_id' => $this->year->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 1,
            'score' => 5,
            'max_score' => 10,
            'status' => AttemptStatus::Graded->value,
            'submitted_at' => '2026-08-10 10:00:00',
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        $grade = new CenterExamGrade([
            'center_id' => $center->id,
            'student_user_id' => $student->id,
            'title' => 'Paper test',
            'total_marks' => 10,
            'score' => 9,
            'sat_on' => '2026-08-20',
        ]);
        $grade->tenant_id = $this->tenant->id;
        $grade->academic_year_id = $this->year->id;
        $grade->save();

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}/exam-results")
            ->assertOk();

        $rows = collect($res->json('data'));
        $this->assertCount(2, $rows);
        // Newest first: the paper grade (20 Aug) precedes the attempt (10 Aug).
        $this->assertSame('center', $rows[0]['kind']);
        $this->assertSame('Paper test', $rows[0]['title']);
        $this->assertSame(90, $rows[0]['percent']);
        $this->assertSame('online', $rows[1]['kind']);
        $this->assertSame('Online quiz', $rows[1]['title']);
        $this->assertSame(50, $rows[1]['percent']);
        $this->assertSame(70, $res->json('meta.average_percent'));
    }

    public function test_titles_survive_a_panel_pinned_to_another_year(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);

        // The student's lesson + exam live in year one; the panel asks as year two.
        $lesson = $this->makeLesson('Chapter one');
        app(EnrollmentService::class)
            ->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Manual);

        $progress = new LessonProgress([
            'lesson_id' => $lesson->id,
            'academic_year_id' => $this->year->id,
            'user_id' => $student->id,
            'watch_percent' => 40,
        ]);
        $progress->tenant_id = $this->tenant->id;
        $progress->save();

        $exam = new Exam(['title' => 'Online quiz', 'lesson_id' => $lesson->id, 'academic_year_id' => $this->year->id]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();
        $attempt = new ExamAttempt([
            'academic_year_id' => $this->year->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 1,
            'score' => 5,
            'max_score' => 10,
            'status' => AttemptStatus::Graded->value,
            'submitted_at' => now(),
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        $otherYear = $this->makeYear('Year Two');

        Sanctum::actingAs($teacher);
        $headers = ['X-Tenant' => 'demo', 'X-Academic-Year' => $otherYear->uuid];

        $enrollments = $this->withHeaders($headers)
            ->getJson("/api/v1/teacher/students/{$student->uuid}/enrollments")->assertOk();
        $this->assertSame('Chapter one', $enrollments->json('data.0.title'));
        $this->assertSame('lesson', $enrollments->json('data.0.target_type'));

        $progressRes = $this->withHeaders($headers)
            ->getJson("/api/v1/teacher/students/{$student->uuid}/progress")->assertOk();
        $this->assertSame('Chapter one', $progressRes->json('data.0.lesson_title'));

        $results = $this->withHeaders($headers)
            ->getJson("/api/v1/teacher/students/{$student->uuid}/exam-results")->assertOk();
        $this->assertSame('Online quiz', $results->json('data.0.title'));
    }

    public function test_a_student_cannot_read_another_students_performance(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $other = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$other->uuid}/attendance")
            ->assertForbidden();
        $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$other->uuid}/exam-results")
            ->assertForbidden();
    }
}
