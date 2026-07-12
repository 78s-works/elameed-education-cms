<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
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
 * Access control + encrypted-HLS delivery shape. A ready rendition is seeded so
 * these run without FFmpeg; the real transcode is exercised in EncryptedHlsTest.
 */
class PlaybackAuthorizationTest extends TestCase
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

    private function student(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function lessonWithVideo(bool $freePreview = false, bool $freeCourse = false): Lesson
    {
        $course = new Course([
            'title' => 'C', 'visibility' => ContentVisibility::Visible->value,
            'price_minor' => 10000, 'is_free' => $freeCourse,
        ]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L', 'is_free_preview' => $freePreview]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/x.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        Storage::disk('local')->put('media/source/x.mp4', 'SOURCE');

        $lesson->update(['video_asset_id' => $asset->id]);

        return $lesson->fresh();
    }

    /** Seed a ready encrypted rendition for (asset, user) so issue() skips FFmpeg. */
    private function seedRendition(int $assetId, int $userId): MediaRendition
    {
        $asset = MediaAsset::withoutGlobalScopes()->find($assetId);
        $dir = "media/hls/{$asset->uuid}/{$userId}";
        Storage::disk('local')->put("{$dir}/index.m3u8",
            "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n"
            .'#EXT-X-KEY:METHOD=AES-128,URI="__KEYURI__",IV=0x'.str_repeat('0', 32)."\n"
            ."#EXTINF:6.0,\nseg_000.ts\n#EXT-X-ENDLIST\n");
        Storage::disk('local')->put("{$dir}/seg_000.ts", 'ENCRYPTED-SEGMENT-BYTES');

        $r = new MediaRendition;
        $r->tenant_id = $this->tenant->id;
        $r->media_asset_id = $assetId;
        $r->user_id = $userId;
        $r->fill(['status' => 'ready', 'hls_dir' => $dir, 'enc_key' => base64_encode(random_bytes(16)), 'iv' => str_repeat('0', 32), 'segment_count' => 1]);
        $r->save();

        return $r;
    }

    public function test_enrolled_student_streams_encrypted_hls_and_gets_raw_key(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);
        $this->seedRendition($lesson->video_asset_id, $student->id);

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'manifest_url', 'key_url', 'expires_at']]);

        $token = $res->json('data.token');
        $manifest = $res->json('data.manifest_url');
        $this->assertStringContainsString("/api/v1/media/stream/{$token}", $manifest);

        // Playlist is encrypted HLS with the key + segment bound to the token — not raw MP4.
        $playlist = $this->get($manifest)->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')->getContent();
        $this->assertStringContainsString('#EXT-X-KEY:METHOD=AES-128', $playlist);
        $this->assertStringContainsString("/api/v1/media/key/{$token}", $playlist);
        $this->assertStringContainsString("/api/v1/media/segment/{$token}/seg_000.ts", $playlist);

        // Segment is served (encrypted); key endpoint returns exactly 16 raw bytes.
        $this->get("/api/v1/media/segment/{$token}/seg_000.ts")->assertOk()->assertHeader('Content-Type', 'video/mp2t');
        $key = $this->get("/api/v1/media/key/{$token}")->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame(16, strlen($key->getContent()));

        // nginx authz accepts the token.
        $this->getJson("/api/v1/internal/media/authz?token={$token}")->assertNoContent();
    }

    public function test_unenrolled_student_is_denied_playback(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(403);
    }

    public function test_free_preview_lesson_is_playable_without_enrollment(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo(freePreview: true);
        $this->seedRendition($lesson->video_asset_id, $student->id);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk();
    }

    public function test_invalid_token_key_and_authz_are_denied(): void
    {
        $this->get('/api/v1/media/key/not-a-real-token')->assertStatus(403);
        $this->get('/api/v1/media/stream/not-a-real-token')->assertStatus(403);
        $this->getJson('/api/v1/internal/media/authz?token=nope')->assertStatus(403);
    }

    public function test_stolen_token_key_stops_working_once_enrollment_lapses(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo();
        $enroll = app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);
        $this->seedRendition($lesson->video_asset_id, $student->id);

        Sanctum::actingAs($student);
        $token = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk()->json('data.token');

        // Key works while enrolled…
        $this->get("/api/v1/media/key/{$token}")->assertOk();

        // …revoke access; the SAME token can no longer fetch the key.
        $enroll->delete();
        $this->get("/api/v1/media/key/{$token}")->assertStatus(403);
    }

    public function test_cross_tenant_lesson_playback_is_404(): void
    {
        $lesson = $this->lessonWithVideo();
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $intruder = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $other->id, 'user_id' => $intruder->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($intruder);
        $this->withHeader('X-Tenant', 'other')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(404);
    }
}
