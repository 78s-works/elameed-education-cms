<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Commerce\Enums\EnrollmentSource;
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
 * B12 (LP-6) — runtime part-visibility filter by student study_mode. On
 * GET /lessons/{lesson}/sections a student sees only parts whose access_mode
 * matches their channel: `both` sees all, `center` sees center+both, `online`
 * sees online+both. Legacy parts (null access_mode) are unrestricted.
 */
class PartVisibilityByStudyModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    public function test_center_student_sees_center_and_both_not_online(): void
    {
        $modes = $this->accessModesFor('center');

        $this->assertEqualsCanonicalizing(['center', 'both', null], $modes);
        $this->assertNotContains('online', $modes);
    }

    public function test_online_student_sees_online_and_both_not_center(): void
    {
        $modes = $this->accessModesFor('online');

        $this->assertEqualsCanonicalizing(['online', 'both', null], $modes);
        $this->assertNotContains('center', $modes);
    }

    public function test_hybrid_student_sees_all_channels(): void
    {
        $modes = $this->accessModesFor('both');

        $this->assertEqualsCanonicalizing(['center', 'online', 'both', null], $modes);
    }

    public function test_student_without_profile_defaults_to_see_all(): void
    {
        // No StudentProfile row → study_mode defaults to `both` (see-all), so a
        // pre-B5 student is never silently hidden from content.
        $modes = $this->accessModesFor(null);

        $this->assertEqualsCanonicalizing(['center', 'online', 'both', null], $modes);
    }

    /**
     * Seed a lesson with one part per access_mode (+ one legacy null part), enroll
     * a student whose study_mode is $studyMode (null = no profile), then return the
     * access_mode of every part the student actually receives.
     *
     * @return array<int, string|null>
     */
    private function accessModesFor(?string $studyMode): array
    {
        $student = $this->student($studyMode);
        $lesson = $this->lesson();

        foreach (['center', 'online', 'both', null] as $mode) {
            $this->part($lesson, $mode);
        }

        app(EnrollmentService::class)->grantLesson(
            $this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase,
        );

        Sanctum::actingAs($student);
        $response = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk();

        return array_column($response->json('data'), 'access_mode');
    }

    private function student(?string $studyMode): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        if ($studyMode !== null) {
            $profile = new StudentProfile(['user_id' => $user->id, 'study_mode' => $studyMode]);
            $profile->tenant_id = $this->tenant->id;
            $profile->save();
        }

        return $user;
    }

    private function lesson(): Lesson
    {
        $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        $lesson = new Lesson(['title' => 'L', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function part(Lesson $lesson, ?string $accessMode): LessonSection
    {
        $part = new LessonSection([
            'lesson_id' => $lesson->id,
            'type' => 'video',
            'title' => 'P-'.($accessMode ?? 'legacy'),
            'access_mode' => $accessMode,
        ]);
        $part->tenant_id = $this->tenant->id;
        $part->save();

        return $part;
    }
}
