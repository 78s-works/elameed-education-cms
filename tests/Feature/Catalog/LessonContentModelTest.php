<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
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

    public function test_video_section_accepts_a_youtube_link_without_an_uploaded_asset(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", [
                'type' => 'lecture_video', 'title' => 'Explain', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'lecture_video')
            ->assertJsonPath('data.youtube_url', 'https://youtu.be/dQw4w9WgXcQ')
            ->assertJsonPath('data.media_asset_id', null);

        $this->assertDatabaseHas('lesson_sections', [
            'lesson_id' => $lesson->id, 'type' => 'lecture_video', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
    }

    public function test_video_section_rejects_missing_media_and_youtube_and_bad_links(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        Sanctum::actingAs($teacher);

        // Neither an uploaded asset nor a YouTube link → 422 (hw_solution is a video).
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", ['type' => 'hw_solution'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['media_asset_id']]]);

        // A string that is not a YouTube link → 422.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", ['type' => 'lecture_video', 'youtube_url' => 'https://vimeo.com/123'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['youtube_url']]]);

        // youtube_url on a non-video section (pdf) → 422.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/sections", ['type' => 'pdf', 'media_asset_id' => 1, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['youtube_url']]]);
    }

    // Solution-video gating (quiz_solution/hw_solution hidden until submit) is
    // covered in LessonProgressionTest.

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
