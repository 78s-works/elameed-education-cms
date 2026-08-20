<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Commerce\Services\EnrollmentService;
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
 * An EXPLICIT grant wins over the channel rule (VD §7).
 *
 * The channel scope is a discovery/acquisition rule: it stops a center student
 * browsing and buying online content, and it is enforced where that happens
 * (PublicCatalogController + CheckoutService). It used to be re-applied at the
 * play gate too, so a teacher granting a center student an online lesson
 * produced a lesson that listed in /me/lessons and then 403'd on open, with
 * nothing to explain why. Grants come from a teacher's hand, a redeemed code, a
 * center check-in or a package fan-out — all of them decisions already taken.
 *
 * Without a grant the channel rule still holds, so an other-channel free preview
 * stays out of reach.
 */
class CrossChannelGrantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = new AcademicYear(['name' => 'الثالث الثانوي', 'sort_order' => 0]);
        $this->year->tenant_id = $this->tenant->id; // no request context in tests
        $this->year->save();
    }

    public function test_center_student_granted_an_online_lesson_can_open_it(): void
    {
        // The reported bug: teacher grants a center student an online lesson, the
        // student sees it in their library and then cannot open it.
        $lesson = $this->lesson('online');
        $student = $this->student('center');

        $this->grant($student, $lesson);

        Sanctum::actingAs($student);

        // It lists…
        $listed = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/lessons')
            ->assertOk()->json('data');
        $this->assertContains($lesson->id, array_column($listed, 'id'));

        // …and it opens.
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'online');
    }

    public function test_the_granted_online_lessons_parts_are_visible_to_a_center_student(): void
    {
        // Opening to an empty part list would be the same bug one layer down: the
        // student's `center` channel would hide every part of the online lesson.
        $lesson = $this->lesson('online');
        $this->part($lesson, 'online');
        $this->part($lesson, 'both');
        $student = $this->student('center');

        $this->grant($student, $lesson);

        Sanctum::actingAs($student);
        $modes = array_column(
            $this->withHeader('X-Tenant', 'demo')
                ->getJson("/api/v1/lessons/{$lesson->id}/sections")
                ->assertOk()
                ->json('data'),
            'access_mode',
        );

        $this->assertEqualsCanonicalizing(['online', 'both'], $modes);
    }

    public function test_a_hybrid_lesson_still_hides_its_online_parts_from_a_center_student(): void
    {
        // B12/LP-6 is untouched: a `both` lesson is visible to every channel, so the
        // student's own study_mode still decides which of its parts they see. Only a
        // lesson whose OWN channel is out of their reach falls back to the lesson's.
        $lesson = $this->lesson('both');
        $this->part($lesson, 'online');
        $this->part($lesson, 'center');
        $this->part($lesson, 'both');
        $student = $this->student('center');

        $this->grant($student, $lesson);

        Sanctum::actingAs($student);
        $modes = array_column(
            $this->withHeader('X-Tenant', 'demo')
                ->getJson("/api/v1/lessons/{$lesson->id}/sections")
                ->assertOk()
                ->json('data'),
            'access_mode',
        );

        $this->assertEqualsCanonicalizing(['center', 'both'], $modes);
        $this->assertNotContains('online', $modes);
    }

    public function test_an_ungranted_online_free_preview_stays_out_of_reach(): void
    {
        // The channel rule still governs everything the student was NOT given: a
        // center student cannot walk into an online lesson just because it is free.
        $lesson = $this->lesson('online', freePreview: true);
        $student = $this->student('center');

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    public function test_a_center_student_still_reaches_an_ungranted_center_free_preview(): void
    {
        $lesson = $this->lesson('center', freePreview: true);
        $student = $this->student('center');

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/lessons/{$lesson->id}")
            ->assertOk();
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * Grant a lesson the way the teacher panel does — inside an academic-year
     * context, so BelongsToAcademicYear stamps `enrollments.academic_year_id`.
     * Without it the row is year-less and the student's year-scoped library query
     * would not see it (a test artifact, not the bug under test).
     */
    private function grant(User $student, Lesson $lesson): void
    {
        app(AcademicYearContext::class)->set($this->year->id);

        try {
            app(EnrollmentService::class)->grantLesson(
                $this->tenant->id, $student->id, $lesson, EnrollmentSource::Manual,
            );
        } finally {
            app(AcademicYearContext::class)->forget();
        }
    }

    private function student(string $studyMode): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);
        $profile = new StudentProfile([
            'user_id' => $user->id,
            'study_mode' => $studyMode,
            'academic_year_id' => $this->year->id,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    private function lesson(string $accessMode, bool $freePreview = false): Lesson
    {
        $lesson = new Lesson([
            'title' => 'L-'.$accessMode,
            'visibility' => ContentVisibility::Visible->value,
            'access_mode' => $accessMode,
            'price_minor' => 10000,
            'is_free_preview' => $freePreview,
        ]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function part(Lesson $lesson, string $accessMode): LessonSection
    {
        $part = new LessonSection([
            'lesson_id' => $lesson->id,
            'type' => 'video',
            'title' => 'P-'.$accessMode,
            'access_mode' => $accessMode,
        ]);
        $part->tenant_id = $this->tenant->id;
        $part->academic_year_id = $lesson->academic_year_id;
        $part->save();

        return $part;
    }
}
