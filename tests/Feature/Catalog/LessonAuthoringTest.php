<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Standalone lesson authoring (VD change set §7/§8, doc 13 Phase 3): the
 * year-scoped lesson CRUD, its typed parts (video/homework/quiz) with the
 * access_mode ceiling + degree/grading rules, and the teacher pass-override.
 */
class LessonAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('2025 / 2026');
    }

    // --- helpers -----------------------------------------------------------

    private function makeYear(string $name, int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function member(TenantUserRole $role, array $permissions = []): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'permissions' => $permissions !== [] ? $permissions : null,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(?AcademicYear $year = null): array
    {
        return ['X-Tenant' => 'demo', 'X-Academic-Year' => ($year ?? $this->year)->uuid];
    }

    private function mediaAsset(): MediaAsset
    {
        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'title' => 'vid']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        return $asset;
    }

    /** Create a lesson through the API and return its id. */
    private function makeLesson(string $accessMode = 'both', string $name = 'Lesson 1'): int
    {
        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/lessons', ['name' => $name, 'access_mode' => $accessMode])
            ->assertStatus(201)->json('data.id');
    }

    // --- lesson CRUD -------------------------------------------------------

    public function test_teacher_can_crud_a_standalone_lesson(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $data = $this->withHeaders($this->headers())->postJson('/api/v1/teacher/lessons', [
            'name' => 'Lesson 1', 'access_mode' => 'both', 'price_minor' => 5000, 'currency' => 'EGP', 'is_purchasable' => true,
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Lesson 1')
            ->assertJsonPath('data.access_mode', 'both')
            ->assertJsonPath('data.availability_days', 7)   // as-built default
            ->assertJsonPath('data.is_purchasable', true)
            ->assertJsonPath('data.sections', [])
            ->json('data');

        $id = $data['id'];
        $this->assertDatabaseHas('lessons', ['id' => $id, 'title' => 'Lesson 1', 'access_mode' => 'both', 'academic_year_id' => $this->year->id]);

        // Show.
        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/lessons/{$id}")
            ->assertOk()->assertJsonPath('data.name', 'Lesson 1');

        // Update (rename + reprice).
        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/lessons/{$id}", ['name' => 'Renamed', 'price_minor' => 9000])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.price_minor', 9000);

        // Index lists it.
        $this->withHeaders($this->headers())->getJson('/api/v1/teacher/lessons')
            ->assertOk()->assertJsonPath('data.0.id', $id);

        // Delete.
        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/lessons/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('lessons', ['id' => $id]);
    }

    public function test_lesson_routes_require_the_academic_year_header(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/teacher/lessons', ['name' => 'X', 'access_mode' => 'both'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_lessons_are_isolated_per_academic_year(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $other = $this->makeYear('Other', 10);

        $id = $this->makeLesson();

        // Not visible / not found under a different year.
        $this->withHeaders($this->headers($other))->getJson('/api/v1/teacher/lessons')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($this->headers($other))->getJson("/api/v1/teacher/lessons/{$id}")->assertStatus(404);
    }

    // --- part type payloads ------------------------------------------------

    public function test_create_video_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');
        $asset = $this->mediaAsset();

        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'Intro', 'type' => 'video', 'access_mode' => 'online', 'is_required' => true, 'media_asset_id' => $asset->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.access_mode', 'online')
            ->assertJsonPath('data.media_asset_id', $asset->id);

        $this->assertDatabaseHas('lesson_sections', ['lesson_id' => $lesson, 'type' => 'video', 'access_mode' => 'online', 'media_asset_id' => $asset->id]);
    }

    public function test_create_pdf_part(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        $asset = new MediaAsset(['type' => MediaType::Pdf->value, 'status' => MediaStatus::Ready->value, 'title' => 'notes.pdf']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'Lecture notes', 'type' => 'pdf', 'access_mode' => 'both', 'is_required' => true,
            'media_asset_id' => $asset->id, 'pdf_kind' => 'lecture_notes',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.pdf_kind', 'lecture_notes')
            ->assertJsonPath('data.media_asset_id', $asset->id);

        $this->assertDatabaseHas('lesson_sections', [
            'lesson_id' => $lesson, 'type' => 'pdf', 'pdf_kind' => 'lecture_notes', 'media_asset_id' => $asset->id, 'exam_id' => null,
        ]);
    }

    public function test_pdf_part_requires_a_file_and_kind(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'Empty pdf', 'type' => 'pdf', 'access_mode' => 'both',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['media_asset_id', 'pdf_kind']]]);
    }

    public function test_create_homework_part_backs_an_exam(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        $examId = $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'HW1', 'type' => 'homework', 'access_mode' => 'both', 'is_required' => true,
            'delivery' => 'pdf_upload', 'grading_mode' => 'manual', 'pass_mode' => 'marks',
            'pass_value' => 30, 'total_marks' => 50, 'gate_rule' => 'must_submit', 'max_tries' => 2,
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'homework')
            ->assertJsonPath('data.delivery', 'pdf_upload')
            ->assertJsonPath('data.gate_rule', 'must_submit')
            ->assertJsonPath('data.max_tries', 2)
            ->assertJsonPath('data.exam.pass_mode', 'marks')
            ->assertJsonPath('data.exam.grading_mode', 'manual')
            ->json('data.exam.id');

        $this->assertDatabaseHas('exams', ['uuid' => $examId, 'lesson_id' => $lesson, 'type' => 'homework', 'pass_mode' => 'marks', 'total_marks' => 50.00]);
    }

    public function test_create_quiz_part_with_bubble_sheet_auto_grade(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'Quiz1', 'type' => 'quiz', 'access_mode' => 'both', 'is_required' => true,
            'delivery' => 'bubble_sheet', 'grading_mode' => 'auto', 'pass_mode' => 'percent',
            'pass_value' => 60, 'gate_rule' => 'must_pass', 'max_tries' => 3, 'duration_min' => 15,
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'quiz')
            ->assertJsonPath('data.exam.mode', 'bubble_sheet')
            ->assertJsonPath('data.exam.grading_mode', 'auto')
            ->assertJsonPath('data.exam.duration_min', 15);

        $this->assertDatabaseHas('exams', ['lesson_id' => $lesson, 'type' => 'lesson_quiz', 'mode' => 'bubble_sheet', 'grading_mode' => 'auto', 'duration_min' => 15]);
    }

    public function test_parts_can_be_reordered(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');
        $a = $this->mediaAsset();

        $first = $this->addVideo($lesson, $a->id, 'A');
        $second = $this->addVideo($lesson, $a->id, 'B');

        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/lessons/{$lesson}/sections/reorder", [
            'order' => [$second, $first],
        ])->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.id', $first);
    }

    private function addVideo(int $lesson, int $assetId, string $name): int
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => $name, 'type' => 'video', 'access_mode' => 'both', 'media_asset_id' => $assetId,
        ])->assertStatus(201)->json('data.id');
    }

    // --- validation: ceiling + degree/grading -----------------------------

    public function test_part_access_mode_must_be_within_the_lesson_ceiling(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('online');   // online ⊇ { online } only
        $asset = $this->mediaAsset();

        // center ⊄ online → 422
        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'P', 'type' => 'video', 'access_mode' => 'center', 'media_asset_id' => $asset->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['access_mode']]]);

        // both ⊄ online → 422
        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'P', 'type' => 'video', 'access_mode' => 'both', 'media_asset_id' => $asset->id,
        ])->assertStatus(422);

        // online ⊆ online → ok
        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'P', 'type' => 'video', 'access_mode' => 'online', 'media_asset_id' => $asset->id,
        ])->assertStatus(201);
    }

    public function test_narrowing_a_lesson_revalidates_existing_parts(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');
        $asset = $this->mediaAsset();
        $this->addVideoMode($lesson, $asset->id, 'center');   // a center part

        // Narrowing both → online orphans the center part → 422.
        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/lessons/{$lesson}", ['access_mode' => 'online'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['access_mode', 'offending_parts']]]);
    }

    private function addVideoMode(int $lesson, int $assetId, string $mode): int
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'P', 'type' => 'video', 'access_mode' => $mode, 'media_asset_id' => $assetId,
        ])->assertStatus(201)->json('data.id');
    }

    public function test_auto_grading_requires_bubble_sheet(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'HW', 'type' => 'homework', 'access_mode' => 'both', 'delivery' => 'pdf_upload',
            'grading_mode' => 'auto', 'pass_mode' => 'percent', 'pass_value' => 50, 'gate_rule' => 'must_submit',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['grading_mode']]]);
    }

    public function test_marks_pass_mode_requires_total_marks(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');

        // Missing total_marks → 422
        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'HW', 'type' => 'homework', 'access_mode' => 'both', 'delivery' => 'pdf_upload',
            'grading_mode' => 'manual', 'pass_mode' => 'marks', 'pass_value' => 20, 'gate_rule' => 'must_submit',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['total_marks']]]);

        // pass_value > total_marks → 422
        $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'HW', 'type' => 'homework', 'access_mode' => 'both', 'delivery' => 'pdf_upload',
            'grading_mode' => 'manual', 'pass_mode' => 'marks', 'pass_value' => 80, 'total_marks' => 50, 'gate_rule' => 'must_submit',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['pass_value']]]);
    }

    // --- pass-override -----------------------------------------------------

    public function test_teacher_grants_and_cannot_duplicate_a_pass_override(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->makeLesson('both');
        $section = $this->addHomework($lesson);
        $student = $this->member(TenantUserRole::Student);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/teacher/lessons/{$lesson}/sections/{$section}/pass-override", ['user_id' => $student->uuid, 'note' => 'manual pass'])
            ->assertStatus(201)
            ->assertJsonPath('data.note', 'manual pass');

        $this->assertDatabaseHas('part_pass_overrides', ['lesson_section_id' => $section, 'user_id' => $student->id]);

        // Duplicate (unique section+user) → 409.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/teacher/lessons/{$lesson}/sections/{$section}/pass-override", ['user_id' => $student->uuid])
            ->assertStatus(409);

        // Revoke → 204, row gone.
        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/teacher/lessons/{$lesson}/sections/{$section}/pass-override/{$student->uuid}")
            ->assertNoContent();
        $this->assertDatabaseMissing('part_pass_overrides', ['lesson_section_id' => $section, 'user_id' => $student->id]);
    }

    public function test_pass_override_is_gated_by_the_homework_permission(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);
        $lesson = $this->makeLesson('both');
        $section = $this->addHomework($lesson);
        $student = $this->member(TenantUserRole::Student);
        $body = ['user_id' => $student->uuid];
        $url = "/api/v1/teacher/lessons/{$lesson}/sections/{$section}/pass-override";

        // Assistant WITHOUT the homework permission → 403.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, []));
        $this->withHeaders($this->headers())->postJson($url, $body)->assertStatus(403);

        // A plain student → 403.
        Sanctum::actingAs($this->member(TenantUserRole::Student));
        $this->withHeaders($this->headers())->postJson($url, $body)->assertStatus(403);

        // Assistant WITH the homework permission → 201.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['homework']));
        $this->withHeaders($this->headers())->postJson($url, $body)->assertStatus(201);
    }

    private function addHomework(int $lesson): int
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/teacher/lessons/{$lesson}/sections", [
            'name' => 'HW', 'type' => 'homework', 'access_mode' => 'both', 'delivery' => 'pdf_upload',
            'grading_mode' => 'manual', 'pass_mode' => 'percent', 'pass_value' => 50, 'gate_rule' => 'must_pass', 'max_tries' => 1,
        ])->assertStatus(201)->json('data.id');
    }
}
