<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\LessonSection;
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
        $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        $lesson = new Lesson(['title' => 'L', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        return $lesson->fresh();
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
    //
    // Section *authoring* moved to the standalone-lesson part model (VD change set
    // §7/§8, doc 13 Phase 3) — the create/validate cases now live in
    // LessonAuthoringTest (typed parts video|homework|quiz, access_mode ceiling,
    // degree/grading rules). Solution-video gating (quiz_solution/hw_solution
    // hidden until submit) stays covered in LessonProgressionTest.

    // ---- Task 3 + 4: Availability, extensions, countdown, lock ----------------

    public function test_start_opens_window_and_access_reports_remaining(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'max_extensions' => 1, 'extension_hours' => 24]);
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

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
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);
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
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/start")->assertOk();
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/extension-request")->assertStatus(409);
    }

    // ---- VD R3/R4: automated per-lesson self-reopen ---------------------------

    public function test_self_reopen_within_limit_extends_24h_and_increments_counter(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'self_reopen_limit' => 2, 'extension_hours' => 24]);
        $window = $this->expiredWindow($student, $lesson);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.extensions_used', 1)
            ->assertJsonPath('data.self_reopens_remaining', 1);

        // Extended ~24h from now and one auto-budget consumed (server counter).
        $window->refresh();
        $this->assertSame(1, (int) $window->extensions_used);
        $this->assertNull($window->locked_at);
        $this->assertGreaterThan(23 * 3600, $window->expires_at->getTimestamp() - now()->getTimestamp());
        $this->assertLessThanOrEqual(24 * 3600, $window->expires_at->getTimestamp() - now()->getTimestamp());
    }

    public function test_self_reopen_returns_409_at_limit(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'self_reopen_limit' => 1, 'extension_hours' => 24]);
        $window = $this->expiredWindow($student, $lesson);

        Sanctum::actingAs($student);
        // First reopen spends the only auto-budget.
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/reopen")->assertOk();

        // Expire again → now over the cap.
        $this->expire($window);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/reopen")
            ->assertStatus(409)
            ->assertSee('reopen_limit_reached');

        $this->assertSame(1, (int) $window->refresh()->extensions_used);
    }

    public function test_self_reopen_rejected_while_window_still_open(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'self_reopen_limit' => 2, 'extension_hours' => 24]);
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        // Running (not expired/locked) window → reopen refused, counter untouched.
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/start")->assertOk();
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/reopen")->assertStatus(409);

        $window = LessonAccessWindow::withoutGlobalScopes()->where('lesson_id', $lesson->id)->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(0, (int) $window->extensions_used);
    }

    public function test_self_reopen_counter_is_server_authoritative_client_count_ignored(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson();
        $lesson->update(['availability_days' => 7, 'self_reopen_limit' => 1, 'extension_hours' => 24]);
        $window = $this->expiredWindow($student, $lesson);

        Sanctum::actingAs($student);
        // Client attempts to inflate its budget/counter via the body — ignored.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/lessons/{$lesson->id}/reopen", ['extensions_used' => 99, 'self_reopen_limit' => 99])
            ->assertOk();
        $this->assertSame(1, (int) $window->refresh()->extensions_used);

        // Still hard-capped at the lesson's server-side limit of 1.
        $this->expire($window);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/lessons/{$lesson->id}/reopen", ['extensions_used' => 0, 'self_reopen_limit' => 99])
            ->assertStatus(409);
    }

    /** Enroll, open the window via /start, then age it past expiry. */
    private function expiredWindow(User $student, Lesson $lesson): LessonAccessWindow
    {
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/lessons/{$lesson->id}/start")->assertOk();

        $window = LessonAccessWindow::withoutGlobalScopes()->where('lesson_id', $lesson->id)->where('user_id', $student->id)->firstOrFail();
        $this->expire($window);

        return $window;
    }

    /**
     * Force a window past expiry via a query-builder update — always persists,
     * unlike a stale model's ->update(), whose second-precision dirty check can
     * treat two same-second `now()->subDay()` values as unchanged and no-op.
     */
    private function expire(LessonAccessWindow $window): void
    {
        LessonAccessWindow::withoutGlobalScopes()->whereKey($window->getKey())
            ->update(['expires_at' => now()->subDay(), 'locked_at' => null]);
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
