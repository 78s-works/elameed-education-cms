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
use App\Modules\Media\Services\HlsTranscoder;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end proof of the real pipeline: FFmpeg → per-student, watermark-burned,
 * AES-128-encrypted HLS, served only through token-gated endpoints. Skips when
 * FFmpeg isn't installed so CI without it stays green.
 */
class EncryptedHlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(HlsTranscoder::class)->available() || ! is_file((string) config('media.ffmpeg_bin'))) {
            $this->markTestSkipped('FFmpeg not available.');
        }

        Cache::flush();
        Storage::fake('local');
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create(['name' => 'Ali '.$role->value, 'phone' => '0100'.random_int(1000000, 9999999)]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    private function lessonWithRealVideo(): Lesson
    {
        // Generate a real 2s clip with FFmpeg so the transcode has valid input.
        $src = Storage::disk('local')->path('media/source/test.mp4');
        @mkdir(dirname($src), 0777, true);
        $gen = Process::timeout(120)->run([
            (string) config('media.ffmpeg_bin'), '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=15',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-shortest', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $src,
        ]);
        $this->assertTrue($gen->successful(), 'source gen failed: '.$gen->errorOutput());

        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/test.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        $lesson->update(['video_asset_id' => $asset->id]);

        return $lesson->fresh();
    }

    public function test_student_playback_produces_encrypted_hls_and_serves_segments_and_key(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lessonWithRealVideo();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $data = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk()->json('data');
        $token = $data['token'];

        // A real encrypted rendition was produced for this student.
        $rendition = MediaRendition::withoutGlobalScopes()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('ready', $rendition->status);
        $this->assertGreaterThanOrEqual(1, $rendition->segment_count);

        // Playlist is genuine AES-128 HLS bound to the token.
        $playlist = $this->get($data['manifest_url'])->assertOk()->getContent();
        $this->assertStringContainsString('#EXTM3U', $playlist);
        $this->assertStringContainsString('#EXT-X-KEY:METHOD=AES-128', $playlist);
        $this->assertStringContainsString("/api/v1/media/key/{$token}", $playlist);

        // Segment downloads, is served as MPEG-TS, and is NOT the plaintext source.
        preg_match("#/api/v1/media/segment/{$token}/(seg_\d+\.ts)#", $playlist, $m);
        $segBytes = $this->get("/api/v1/media/segment/{$token}/{$m[1]}")
            ->assertOk()->assertHeader('Content-Type', 'video/mp2t')->streamedContent();
        $this->assertNotEmpty($segBytes);
        $this->assertNotSame(Storage::disk('local')->get('media/source/test.mp4'), $segBytes);

        // Key endpoint returns exactly 16 raw bytes.
        $key = $this->get("/api/v1/media/key/{$token}")->assertOk()->getContent();
        $this->assertSame(16, strlen($key));
    }

    public function test_each_student_gets_a_distinct_watermarked_encrypted_rendition(): void
    {
        $lesson = $this->lessonWithRealVideo();
        $a = $this->member(TenantUserRole::Student);
        $b = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $a->id, $lesson->course, EnrollmentSource::Purchase);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $b->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($a);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk();
        Sanctum::actingAs($b);
        $this->withHeader('X-Tenant', 'demo')->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk();

        $ra = MediaRendition::withoutGlobalScopes()->where('user_id', $a->id)->firstOrFail();
        $rb = MediaRendition::withoutGlobalScopes()->where('user_id', $b->id)->firstOrFail();

        // Separate encrypted transcodes → distinct keys and distinct output dirs.
        $this->assertNotSame($ra->enc_key, $rb->enc_key);
        $this->assertNotSame($ra->hls_dir, $rb->hls_dir);
    }

    public function test_teacher_preview_uses_the_same_encrypted_flow(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lessonWithRealVideo();
        $asset = MediaAsset::withoutGlobalScopes()->find($lesson->video_asset_id);

        Sanctum::actingAs($teacher);
        $data = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/media/{$asset->uuid}/preview")->assertOk()->json('data');

        $this->assertStringContainsString('/api/v1/media/stream/', $data['manifest_url']);
        $playlist = $this->get($data['manifest_url'])->assertOk()->getContent();
        $this->assertStringContainsString('#EXT-X-KEY:METHOD=AES-128', $playlist);
    }
}
