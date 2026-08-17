<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Centers\Models\Center;
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
 * Section-level center attendance: a teacher checks a center student in for a
 * lesson part, which opens that part's parent lesson online (enrollment +
 * availability window) for the lesson's availability window. Online-only
 * students are skipped; revoke locks the window and cancels the grant.
 */
class SectionAttendanceTest extends TestCase
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

    public function test_checkin_opens_the_lesson_and_skips_online_students(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        [$lesson, $section] = $this->centerLessonSection(7);

        $centerStudent = $this->student('center');
        $onlineStudent = $this->student('online');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/attendance/checkin', [
            'center_uuid' => $center->uuid,
            'lesson_section_id' => $section->id,
            'students' => [$centerStudent->uuid, $onlineStudent->uuid],
        ])
            ->assertOk()
            ->assertJsonPath('data.marked', 1)
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0', $onlineStudent->uuid);

        // Entitlement + time-box created for the center student only.
        $this->assertDatabaseHas('enrollments', [
            'tenant_id' => $this->tenant->id,
            'academic_year_id' => $this->year->id,
            'user_id' => $centerStudent->id,
            'lesson_id' => $lesson->id,
            'source' => 'center',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('lesson_access_windows', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $centerStudent->id,
            'lesson_id' => $lesson->id,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $centerStudent->id,
            'lesson_section_id' => $section->id,
            'source' => 'center',
        ]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $onlineStudent->id]);
    }

    public function test_active_and_roster_list_the_grant_then_revoke_locks_it(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        [$lesson, $section] = $this->centerLessonSection(7);
        $student = $this->student('center');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/attendance/checkin', [
            'center_uuid' => $center->uuid,
            'lesson_section_id' => $section->id,
            'students' => [$student->uuid],
        ])->assertOk();

        // Active grants.
        $recordId = $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student.uuid', $student->uuid)
            ->assertJsonPath('data.0.active', true)
            ->json('data.0.id');

        // Roster grouped by part.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/lessons')
            ->assertOk()
            ->assertJsonPath('data.0.section.id', $section->id)
            ->assertJsonCount(1, 'data.0.attendees');

        // Revoke → grant cancelled, window locked.
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/attendance/active/{$recordId}")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id, 'lesson_id' => $lesson->id, 'source' => 'center', 'status' => 'cancelled',
        ]);
        $window = \DB::table('lesson_access_windows')
            ->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($window->locked_at);

        // The revoked grant drops off the active list.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/attendance/active')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_checkin_rejects_a_non_center_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->makeCenter();
        [, $section] = $this->centerLessonSection(7, 'online');
        $student = $this->student('online');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/attendance/checkin', [
            'center_uuid' => $center->uuid,
            'lesson_section_id' => $section->id,
            'students' => [$student->uuid],
        ])->assertStatus(422);
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
            'user_id' => $user->id,
            'academic_year_id' => $this->year->id,
            'study_mode' => $studyMode,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    /**
     * A course + lesson (with an availability window) + one part, all stamped
     * with the active academic year via the context so the year-scoped routes
     * resolve them.
     *
     * @return array{0: Lesson, 1: LessonSection}
     */
    private function centerLessonSection(int $availabilityDays, string $accessMode = 'center'): array
    {
        app(AcademicYearContext::class)->set($this->year->id);

        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        $lesson = new Lesson(['course_id' => $course->id, 'title' => 'L', 'availability_days' => $availabilityDays]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $section = new LessonSection([
            'lesson_id' => $lesson->id, 'type' => 'video', 'title' => 'Part 1', 'access_mode' => $accessMode,
        ]);
        $section->tenant_id = $this->tenant->id;
        $section->save();

        app(AcademicYearContext::class)->forget();

        return [$lesson->fresh(), $section->fresh()];
    }
}
