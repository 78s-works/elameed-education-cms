<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\ContentDependency;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The lesson content-model epic: typed sections (FR-M04-01), content
 * dependencies / unlock rules, time-boxed availability + extensions, and the
 * countdown/lock enforcement on playback.
 */
class LessonContentModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
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

    private function lesson(): Lesson
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function pdfAsset(): MediaAsset
    {
        $asset = new MediaAsset(['type' => MediaType::Pdf->value, 'status' => MediaStatus::Ready->value, 'title' => 'Answer sheet']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        return $asset;
    }

    /** @param array<string, mixed> $attrs */
    private function section(Lesson $lesson, array $attrs): LessonSection
    {
        $section = new LessonSection(['lesson_id' => $lesson->id] + $attrs);
        $section->tenant_id = $this->tenant->id;
        $section->save();

        return $section;
    }

    private function exam(Lesson $lesson): Exam
    {
        $exam = new Exam(['course_id' => $lesson->course_id, 'lesson_id' => $lesson->id, 'title' => 'Quiz', 'type' => 'exam', 'pass_percent' => 50, 'is_published' => true]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();

        return $exam;
    }

    // ---- Task 1: Flexible Lesson Content Structure ----------------------------

    public function test_teacher_creates_typed_sections(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        $pdf = $this->pdfAsset();

        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", [
                'type' => 'pdf', 'title' => 'Notes', 'media_asset_id' => $pdf->id, 'pdf_kind' => 'lecture_notes', 'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.pdf_kind', 'lecture_notes');

        $this->assertDatabaseHas('lesson_sections', ['lesson_id' => $lesson->id, 'type' => 'pdf', 'pdf_kind' => 'lecture_notes']);
    }

    public function test_pdf_section_requires_media_and_rejects_pdf_kind_on_non_pdf(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        Sanctum::actingAs($teacher);

        // pdf without media_asset_id → 422
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", ['type' => 'pdf'])
            ->assertStatus(422);

        // pdf_kind on a video section → 422
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", ['type' => 'lecture_video', 'media_asset_id' => 1, 'pdf_kind' => 'lecture_notes'])
            ->assertStatus(422);
    }

    // ---- Task 2: Content Dependencies & Unlock Rules --------------------------

    public function test_mandatory_dependency_locks_pdf_until_quiz_submitted(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $exam = $this->exam($lesson);
        $pdf = $this->pdfAsset();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        $quizSection = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id, 'sort_order' => 1]);
        $answerSheet = $this->section($lesson, ['type' => 'pdf', 'media_asset_id' => $pdf->id, 'pdf_kind' => 'exam_answer_sheet', 'sort_order' => 2]);

        // Teacher wires the mandatory "submitted" gate.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections/{$answerSheet->id}/dependencies", [
                'depends_on_section_id' => $quizSection->id, 'trigger' => 'submitted', 'enforcement' => 'mandatory',
            ])
            ->assertCreated();

        // Student sees the answer sheet LOCKED before submitting.
        Sanctum::actingAs($student);
        $locked = $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")->assertOk();
        $this->assertTrue($this->sectionLocked($locked->json('data'), $answerSheet->id));

        // Submit an attempt → the sheet unlocks.
        $attempt = new ExamAttempt(['exam_id' => $exam->id, 'user_id' => $student->id, 'status' => 'submitted', 'submitted_at' => now(), 'score' => 8, 'max_score' => 10]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        $unlocked = $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")->assertOk();
        $this->assertFalse($this->sectionLocked($unlocked->json('data'), $answerSheet->id));
    }

    public function test_optional_dependency_never_locks(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $exam = $this->exam($lesson);
        $pdf = $this->pdfAsset();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        $quiz = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $exam->id]);
        $sheet = $this->section($lesson, ['type' => 'pdf', 'media_asset_id' => $pdf->id]);
        $dep = new ContentDependency(['section_id' => $sheet->id, 'depends_on_section_id' => $quiz->id, 'trigger' => 'submitted', 'enforcement' => 'optional']);
        $dep->tenant_id = $this->tenant->id;
        $dep->save();

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections")->assertOk();
        $this->assertFalse($this->sectionLocked($res->json('data'), $sheet->id));
    }

    /** @param array<int, array<string, mixed>> $sections */
    private function sectionLocked(array $sections, int $id): bool
    {
        foreach ($sections as $section) {
            if ((int) $section['id'] === $id) {
                return (bool) ($section['locked'] ?? false);
            }
        }
        $this->fail("Section {$id} not in response.");
    }

    // ---- Task 3 + 4: Availability, extensions, countdown, lock ----------------

    public function test_start_opens_window_and_access_reports_remaining(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'max_extensions' => 1, 'extension_hours' => 24]);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);

        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/start")
            ->assertOk()
            ->assertJsonPath('data.started', true)
            ->assertJsonPath('data.locked', false);

        $access = $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/access")->assertOk();
        $this->assertGreaterThan(0, $access->json('data.remaining_sec'));
        $this->assertDatabaseHas('lesson_access_windows', ['lesson_id' => $lesson->id, 'user_id' => $student->id]);
    }

    public function test_expired_window_blocks_playback_then_granted_extension_restores_it(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lessonWithVideo();
        $lesson->update(['availability_days' => 7, 'max_extensions' => 1, 'extension_hours' => 24]);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);
        $this->seedRendition($lesson->video_asset_id, $student->id);

        // First play opens the window and succeeds.
        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk();

        // Age the window past expiry → playback now blocked.
        $window = LessonAccessWindow::withoutGlobalScopes()->where('lesson_id', $lesson->id)->where('user_id', $student->id)->firstOrFail();
        $window->update(['expires_at' => now()->subDay()]);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertStatus(403);

        // Student requests an extension.
        $reqId = $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/extension-request")
            ->assertCreated()->json('data.id');

        // Teacher grants it → window restored, playback works again.
        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/teacher/extension-requests/{$reqId}/grant")
            ->assertOk()->assertJsonPath('data.status', 'granted');

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk();
    }

    public function test_extension_denied_when_none_allowed(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'max_extensions' => 0]);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/start")->assertOk();
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/extension-request")->assertStatus(409);
    }

    // --- helpers borrowed from the playback test for the video-backed lesson ---

    private function lessonWithVideo(): Lesson
    {
        $lesson = $this->lesson();
        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/x.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        Storage::disk('local')->put('media/source/x.mp4', 'SOURCE');
        $lesson->update(['video_asset_id' => $asset->id]);

        return $lesson->fresh();
    }

    private function seedRendition(int $assetId, int $userId): MediaRendition
    {
        $asset = MediaAsset::withoutGlobalScopes()->find($assetId);
        $dir = "media/hls/{$asset->uuid}/{$userId}";
        Storage::disk('local')->put("{$dir}/index.m3u8", "#EXTM3U\n#EXT-X-ENDLIST\n");
        Storage::disk('local')->put("{$dir}/seg_000.ts", 'ENC');

        $r = new MediaRendition;
        $r->tenant_id = $this->tenant->id;
        $r->media_asset_id = $assetId;
        $r->user_id = $userId;
        $r->fill(['status' => 'ready', 'hls_dir' => $dir, 'enc_key' => base64_encode(random_bytes(16)), 'iv' => str_repeat('0', 32), 'segment_count' => 1]);
        $r->save();

        return $r;
    }
}
