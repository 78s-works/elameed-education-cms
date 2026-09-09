<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The student's own grade history (GET /me/results) and sign-in history
 * (GET /me/login-history) — server-owned, so the same account shows the same
 * history on any device, and never another academy's or another year's rows.
 */
class StudentResultsTest extends TestCase
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

    private function makeYear(string $name, ?Tenant $tenant = null): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = ($tenant ?? $this->tenant)->id;
        $year->save();

        return $year;
    }

    /** An active student member of the tenant, pinned to an academic year. */
    private function student(?AcademicYear $year = null, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value, 'joined_at' => now()->subDays(10),
        ]);
        $profile = new StudentProfile([
            'academic_year_id' => ($year ?? $this->year)->id,
            'study_mode' => 'online',
        ]);
        $profile->tenant_id = $tenant->id;
        $profile->user_id = $user->id;
        $profile->save();

        return $user;
    }

    private function gradedAttempt(User $student, string $title, int $score, int $max, string $at, ?AcademicYear $year = null): ExamAttempt
    {
        $year ??= $this->year;

        $lesson = new Lesson(['title' => $title.' lesson', 'visibility' => ContentVisibility::Visible->value]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        $exam = new Exam(['title' => $title, 'lesson_id' => $lesson->id, 'academic_year_id' => $year->id]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();

        $attempt = new ExamAttempt([
            'academic_year_id' => $year->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 1,
            'score' => $score,
            'max_score' => $max,
            'status' => AttemptStatus::Graded->value,
            'submitted_at' => $at,
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        return $attempt;
    }

    private function paperGrade(User $student, string $title, int $score, int $max, string $satOn): CenterExamGrade
    {
        $center = new Center(['name' => 'Maadi', 'is_active' => true]);
        $center->tenant_id = $this->tenant->id;
        $center->save();

        $grade = new CenterExamGrade([
            'center_id' => $center->id,
            'student_user_id' => $student->id,
            'title' => $title,
            'total_marks' => $max,
            'score' => $score,
            'sat_on' => $satOn,
        ]);
        $grade->tenant_id = $this->tenant->id;
        $grade->academic_year_id = $this->year->id;
        $grade->save();

        return $grade;
    }

    public function test_results_merge_online_attempts_and_paper_grades_newest_first(): void
    {
        $student = $this->student();
        $attempt = $this->gradedAttempt($student, 'Online quiz', 5, 10, '2026-08-10 10:00:00');
        $this->paperGrade($student, 'Paper test', 9, 10, '2026-08-20');

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/results')->assertOk();

        $rows = collect($res->json('data'));
        $this->assertCount(2, $rows);
        $this->assertSame('center', $rows[0]['kind']);   // 20 Aug
        $this->assertSame(90, $rows[0]['percent']);
        $this->assertSame('Maadi', $rows[0]['center']);
        $this->assertSame('online', $rows[1]['kind']);   // 10 Aug
        $this->assertSame('Online quiz', $rows[1]['title']);
        $this->assertSame(50, $rows[1]['percent']);
        // The online row is addressable, so the history can link to the result screen.
        $this->assertSame($attempt->id, $rows[1]['attempt_id']);
        $this->assertNotNull($rows[1]['exam_uuid']);
        $this->assertSame(70, $res->json('meta.average_percent'));
        $this->assertSame(2, $res->json('meta.total'));
    }

    public function test_an_in_progress_attempt_is_not_a_result_yet(): void
    {
        $student = $this->student();
        $attempt = $this->gradedAttempt($student, 'Started', 0, 10, '2026-08-10 10:00:00');
        $attempt->update(['status' => AttemptStatus::InProgress->value]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/results')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_results_are_scoped_to_the_students_own_year(): void
    {
        $otherYear = $this->makeYear('Year Two');
        $student = $this->student();
        $this->gradedAttempt($student, 'This year', 8, 10, '2026-08-11 10:00:00');
        $this->gradedAttempt($student, 'Another year', 3, 10, '2026-08-12 10:00:00', $otherYear);

        Sanctum::actingAs($student);
        // The header names the OTHER year: a student is pinned server-side, so it
        // changes nothing — they see their own year either way.
        $titles = collect($this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $otherYear->uuid])
            ->getJson('/api/v1/me/results')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['This year'], $titles);
    }

    public function test_a_student_never_sees_another_students_results(): void
    {
        $mine = $this->student();
        $theirs = $this->student();
        $this->gradedAttempt($theirs, 'Not mine', 10, 10, '2026-08-10 10:00:00');
        $this->paperGrade($theirs, 'Also not mine', 10, 10, '2026-08-11');

        Sanctum::actingAs($mine);
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/results')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.average_percent', null);
    }

    public function test_a_student_of_another_academy_sees_none_of_it(): void
    {
        $mine = $this->student();
        $this->gradedAttempt($mine, 'Demo academy exam', 7, 10, '2026-08-10 10:00:00');

        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'status' => TenantStatus::Active]);
        $outsider = $this->student($this->makeYear('Their year', $other), $other);

        Sanctum::actingAs($outsider);
        $this->withHeader('X-Tenant', 'rival')->getJson('/api/v1/me/results')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_results_reject_an_out_of_range_limit(): void
    {
        Sanctum::actingAs($this->student());
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/results?limit=500')
            ->assertStatus(422);
    }

    // ── Login history ─────────────────────────────────────────────────────────

    private function loginRow(User $user, bool $success, string $at, ?string $agent = null, ?Tenant $tenant = null): void
    {
        $row = LoginAttempt::create([
            'user_id' => $user->id,
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'identifier' => $user->phone,
            'ip' => '198.51.100.7',
            'user_agent' => $agent ?? 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36',
            'success' => $success,
        ]);
        // created_at isn't fillable on the model (it is written by the login
        // action, never by a client), so backdate the row after inserting it.
        $row->forceFill(['created_at' => $at])->save();
    }

    public function test_login_history_lists_the_students_own_successful_sign_ins(): void
    {
        $student = $this->student();
        $this->loginRow($student, true, '2026-09-01 08:00:00');
        $this->loginRow($student, true, '2026-09-02 09:30:00', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');

        Sanctum::actingAs($student);
        $rows = collect($this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/me/login-history')->assertOk()->json('data'));

        $this->assertCount(2, $rows);
        // Newest first, with a readable device label beside the raw agent.
        $this->assertSame('Safari · iPhone', $rows[0]['device']);
        $this->assertSame('198.51.100.7', $rows[0]['ip']);
        $this->assertStringContainsString('iPhone', $rows[0]['user_agent']);
        $this->assertSame('Chrome · Windows', $rows[1]['device']);
    }

    public function test_login_history_hides_failures_other_students_and_other_academies(): void
    {
        $student = $this->student();
        $other = $this->student();
        $this->loginRow($student, true, '2026-09-02 09:00:00');
        $this->loginRow($student, false, '2026-09-02 08:59:00');   // their own typo
        $this->loginRow($other, true, '2026-09-02 08:00:00');      // someone else

        $rival = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'status' => TenantStatus::Active]);
        $this->loginRow($student, true, '2026-09-01 07:00:00', null, $rival); // same person, other academy

        Sanctum::actingAs($student);
        $rows = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/me/login-history')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-02T09:00:00+00:00', $rows[0]['at']);
    }

    public function test_login_history_honours_a_limit_and_rejects_a_silly_one(): void
    {
        $student = $this->student();
        foreach (range(1, 5) as $i) {
            $this->loginRow($student, true, '2026-09-0'.$i.' 08:00:00');
        }

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/login-history?limit=2')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/login-history?limit=500')
            ->assertStatus(422);
    }

    public function test_an_unknown_user_agent_is_reported_as_unknown_not_guessed(): void
    {
        $student = $this->student();
        $this->loginRow($student, true, '2026-09-02 09:00:00', 'curl/8.4.0');

        Sanctum::actingAs($student);
        $rows = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/me/login-history')->assertOk()->json('data');

        $this->assertNull($rows[0]['device']);
        $this->assertSame('curl/8.4.0', $rows[0]['user_agent']);
    }
}
