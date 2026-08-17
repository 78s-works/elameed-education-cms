<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\ContentVisibility;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gap 1 — manual teacher/assistant access overrides: a staff grant lets one
 * student open a gated section, bypassing an unmet dependency; revoke restores
 * the gate; grant/revoke are audit-logged.
 *
 * Courses AND units are retired (VD §7): lessons are standalone. The old
 * unit-based cross-lesson progression gate (and unit-target overrides) no longer
 * exist, so those scenarios were removed; the surviving in-lesson solution gate
 * (quiz → its solution section hidden until submitted) is exercised here.
 */
class ContentAccessOverrideTest extends TestCase
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

    private function exam(Lesson $lesson, array $attrs): Exam
    {
        // array_merge (not +) so $attrs['type'] overrides the default.
        $exam = new Exam(array_merge(
            ['lesson_id' => $lesson->id, 'title' => 'X', 'type' => 'lesson_quiz', 'pass_percent' => 50, 'is_published' => true],
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

    private function enroll(User $student, Lesson $lesson): void
    {
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);
    }

    // ---- section-level override unlocks one gated (solution) section ------------

    public function test_section_override_unlocks_a_solution_locked_section(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $this->enroll($student, $lesson);

        // A quiz + its solution video — the solution is hidden until the quiz is submitted.
        $this->exam($lesson, ['type' => 'lesson_quiz', 'lesson_id' => $lesson->id]);
        $solution = $this->section($lesson, ['type' => 'quiz_solution', 'media_asset_id' => 1, 'sort_order' => 0]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.0.locked', true);

        // Grant a section override on the gated solution section.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'section', 'target_id' => $solution->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'section')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('content_access_overrides', [
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'section_id' => $solution->id, 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content_access.override_granted', 'tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")
            ->assertOk()
            ->assertJsonPath('data.0.locked', false);
    }

    // ---- guards -----------------------------------------------------------------

    public function test_override_target_must_exist_in_tenant(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/students/{$student->uuid}/content-overrides", [
                'target_type' => 'lesson', 'target_id' => 999999,
            ])
            ->assertStatus(422);
    }
}
