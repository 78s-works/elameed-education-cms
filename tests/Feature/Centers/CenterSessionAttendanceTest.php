<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterSession;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Center sessions CRUD + session-based attendance: a check-in opens every lesson
 * the session bundles online for the student; a session with attendance can't be
 * deleted; online students are skipped; revoke locks the windows.
 */
class CenterSessionAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('Year A');
        $this->h = ['X-Tenant' => 'demo', 'X-Academic-Year' => $this->year->uuid];
    }

    public function test_teacher_cruds_a_session_and_delete_is_blocked_by_attendance(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        $lesson = $this->lesson(7);

        // Create → 201 with the linked lesson.
        $id = $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-sessions', [
            'center_uuid' => $center->uuid,
            'name' => 'Saturday session',
            'session_at' => '2026-08-22T16:00:00Z',
            'lessons' => [$lesson->id],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Saturday session')
            ->assertJsonPath('data.center.uuid', $center->uuid)
            ->assertJsonCount(1, 'data.lessons')
            ->json('data.id');

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-sessions')
            ->assertOk()->assertJsonPath('meta.total', 1);

        // No attendance yet → deletable.
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/center-sessions/{$id}")->assertOk();
        $this->assertDatabaseMissing('center_sessions', ['id' => $id]);
    }

    public function test_checkin_opens_session_lessons_and_skips_online_students(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        $lesson = $this->lesson(7);
        $session = $this->makeSession($center, [$lesson]);

        $centerStudent = $this->student('center');
        $onlineStudent = $this->student('online');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/attendance/checkin', [
            'center_uuid' => $center->uuid,
            'center_session_id' => $session->id,
            'students' => [$centerStudent->uuid, $onlineStudent->uuid],
        ])
            ->assertOk()
            ->assertJsonPath('data.marked', 1)
            ->assertJsonPath('data.skipped.0', $onlineStudent->uuid);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $centerStudent->id, 'lesson_id' => $lesson->id, 'source' => 'center', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('lesson_access_windows', [
            'user_id' => $centerStudent->id, 'lesson_id' => $lesson->id,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $centerStudent->id, 'center_session_id' => $session->id, 'source' => 'center',
        ]);

        // Now the session has attendance → delete is refused.
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/center-sessions/{$session->id}")
            ->assertStatus(422);
    }

    public function test_active_roster_then_revoke_locks_the_window(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        $lesson = $this->lesson(7);
        $session = $this->makeSession($center, [$lesson]);
        $student = $this->student('center');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/attendance/checkin', [
            'center_uuid' => $center->uuid,
            'center_session_id' => $session->id,
            'students' => [$student->uuid],
        ])->assertOk();

        $recordId = $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student.uuid', $student->uuid)
            ->assertJsonPath('data.0.active', true)
            ->json('data.0.id');

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/roster')
            ->assertOk()
            ->assertJsonPath('data.0.session.id', $session->id)
            ->assertJsonCount(1, 'data.0.attendees');

        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/attendance/active/{$recordId}")
            ->assertOk()->assertJsonPath('data.revoked', true);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id, 'lesson_id' => $lesson->id, 'source' => 'center', 'status' => 'cancelled',
        ]);
        $window = \DB::table('lesson_access_windows')
            ->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($window->locked_at);

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/active')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // --- helpers ------------------------------------------------------------

    private function makeYear(string $name): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function makeCenter(): Center
    {
        $center = new Center(['name' => 'Main Branch']);
        $center->tenant_id = $this->tenant->id;
        $center->save();

        return $center;
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

    private function student(string $studyMode): User
    {
        $user = $this->member(TenantUserRole::Student);
        $profile = new StudentProfile([
            'user_id' => $user->id, 'academic_year_id' => $this->year->id, 'study_mode' => $studyMode,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    private function lesson(int $availabilityDays): Lesson
    {
        app(AcademicYearContext::class)->set($this->year->id);

        // Standalone lesson (no course) — access is per-lesson now.
        $lesson = new Lesson(['title' => 'L', 'availability_days' => $availabilityDays]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        app(AcademicYearContext::class)->forget();

        return $lesson->fresh();
    }

    /** A session at $center bundling $lessons, stamped with the active year. */
    private function makeSession(Center $center, array $lessons): CenterSession
    {
        app(AcademicYearContext::class)->set($this->year->id);

        $session = new CenterSession(['center_id' => $center->id, 'name' => 'S', 'session_at' => now()]);
        $session->tenant_id = $this->tenant->id;
        $session->save();
        $session->lessons()->sync(array_map(fn (Lesson $l) => $l->id, $lessons));

        app(AcademicYearContext::class)->forget();

        return $session;
    }
}
