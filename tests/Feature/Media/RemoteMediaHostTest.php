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
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCallbackEvent;
use App\Modules\Media\Models\MediaUploadSession;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Media\Support\PlaybackTokenIssuer;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Remote Media Host integration, driven entirely by Http::fake — no real host,
 * no real credentials. Covers upload intent, idempotency, completion→processing,
 * signed callbacks (apply / replay / bad signature / invalid transition),
 * playback authorization, cross-tenant protection, and the no-silent-fallback
 * guard.
 */
class RemoteMediaHostTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'media.provider' => 'remote',
            'media.host.base_url' => 'https://media.test',
            'media.host.api_key' => 'test-key',
            'media.host.api_secret' => 'test-secret',
            'media.host.callback_secret' => 'cb-secret',
            'media.host.api_version' => 'v1',
        ]);
        $this->fakeHost();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function fakeHost(array $overrides = []): void
    {
        Http::fake(array_merge([
            'media.test/v1/uploads' => Http::response(['upload_id' => 'up_1', 'protocol' => 'tus', 'upload_url' => 'https://media.test/tus/up_1', 'max_bytes' => 1073741824, 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'media.test/v1/uploads/*/complete' => Http::response(['upload_id' => 'up_1', 'state' => 'uploaded', 'bytes_received' => 1000], 200),
            'media.test/v1/uploads/*/process' => Http::response(['job_id' => 'job_1', 'host_video_id' => 'vid_1', 'state' => 'processing'], 202),
            'media.test/v1/videos/*/quarantine' => Http::response(['state' => 'quarantined'], 200),
            'media.test/v1/videos/*/restore' => Http::response(['state' => 'ready'], 200),
            'media.test/v1/videos/*' => Http::response(['state' => 'purged'], 200),
            'media.test/v1/health' => Http::response(['status' => 'ok', 'version' => 'v1'], 200),
        ], $overrides));
    }

    private function member(TenantUserRole $role, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function lesson(bool $freePreview = false): Lesson
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L', 'is_free_preview' => $freePreview]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function startUpload(User $teacher, ?int $lessonId, ?string $key = null)
    {
        Sanctum::actingAs($teacher);

        return $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/remote-videos/uploads', array_filter([
            'lesson_id' => $lessonId,
            'filename' => 'lecture.mp4',
            'size_bytes' => 500000,
            'content_type' => 'video/mp4',
            'idempotency_key' => $key,
        ]));
    }

    /** Drive a video to ready for a lesson; returns [lesson, videoUuid, version]. */
    private function makeReadyVideo(): array
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        $start = $this->startUpload($teacher, $lesson->id)->assertStatus(201);
        $uuid = $start->json('data.video');
        $sessionId = $start->json('data.upload.upload_session');

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/teacher/remote-videos/uploads/{$sessionId}/complete")->assertOk();

        $this->sendCallback([
            'type' => 'processing.completed', 'video_ref' => $uuid, 'version' => 1,
            'host_video_id' => 'vid_1', 'state' => 'ready', 'playback_id' => 'pb_1', 'duration_sec' => 120,
            'thumbnail_url' => 'https://media.test/thumbnails/vid_1.jpg',
        ])->assertOk();

        return [$lesson, $uuid, 1];
    }

    private function sendCallback(array $payload, ?string $eventId = null, ?string $secret = null)
    {
        $body = json_encode($payload);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'.'.$body, $secret ?? 'cb-secret', true));

        return $this->call('POST', '/api/v1/media/callbacks/processing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDIA_EVENT_ID' => $eventId ?? ('ev_'.uniqid()),
            'HTTP_X_MEDIA_TIMESTAMP' => $ts,
            'HTTP_X_MEDIA_SIGNATURE' => $sig,
        ], $body);
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    public function test_remote_endpoint_is_rejected_when_provider_is_local(): void
    {
        config(['media.provider' => 'local']);
        $teacher = $this->member(TenantUserRole::Teacher);

        $this->startUpload($teacher, null)->assertStatus(409);   // no silent fallback
    }

    public function test_start_upload_creates_intent_version_and_session(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();

        $res = $this->startUpload($teacher, $lesson->id)->assertStatus(201)
            ->assertJsonPath('data.upload.protocol', 'tus')
            ->assertJsonPath('data.state', 'uploading');

        $this->assertSame('https://media.test/tus/up_1', $res->json('data.upload.upload_url'));

        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $res->json('data.video'))->firstOrFail();
        $this->assertSame('remote', $asset->provider);
        $this->assertSame($asset->id, $lesson->fresh()->video_asset_id);
        $this->assertSame(1, MediaVersion::withoutGlobalScopes()->where('media_asset_id', $asset->id)->count());
    }

    public function test_start_upload_is_idempotent(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);

        $a = $this->startUpload($teacher, null, 'key-123')->assertStatus(201);
        $b = $this->startUpload($teacher, null, 'key-123')->assertStatus(201);

        $this->assertSame($a->json('data.upload.upload_session'), $b->json('data.upload.upload_session'));
        $this->assertSame(1, MediaUploadSession::withoutGlobalScopes()->count());
        // Only one create-upload call reached the host.
        Http::assertSentCount(1);
    }

    public function test_complete_upload_triggers_processing(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $start = $this->startUpload($teacher, null)->assertStatus(201);
        $sessionId = $start->json('data.upload.upload_session');

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/teacher/remote-videos/uploads/{$sessionId}/complete")
            ->assertOk()->assertJsonPath('data.state', 'uploaded');

        $version = MediaVersion::withoutGlobalScopes()->where('version', 1)->firstOrFail();
        $this->assertSame('processing', $version->state->value);   // sync queue ran the job
        $this->assertSame('vid_1', $version->host_video_id);
    }

    public function test_ready_callback_marks_version_ready_and_sets_current(): void
    {
        [$lesson, $uuid] = $this->makeReadyVideo();

        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $version = MediaVersion::withoutGlobalScopes()->where('media_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('ready', $version->state->value);
        $this->assertSame('pb_1', $version->playback_id);
        $this->assertSame($version->id, $asset->fresh()->current_version_id);
        // Thumbnail from the callback is stored on the version and surfaced on the asset.
        $this->assertSame('https://media.test/thumbnails/vid_1.jpg', $version->thumbnail_url);
        $this->assertSame('https://media.test/thumbnails/vid_1.jpg', $asset->fresh()->thumbnail_url);
    }

    public function test_duplicate_callback_is_applied_once(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $lesson = $this->lesson();
        $uuid = $this->startUpload($teacher, $lesson->id)->json('data.video');
        $sessionId = MediaUploadSession::withoutGlobalScopes()->first()->id;
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson("/api/v1/teacher/remote-videos/uploads/{$sessionId}/complete");

        $payload = ['type' => 'processing.completed', 'video_ref' => $uuid, 'version' => 1, 'host_video_id' => 'vid_1', 'state' => 'ready', 'playback_id' => 'pb_1'];

        $this->sendCallback($payload, 'ev_same')->assertOk()->assertJsonPath('duplicate', null);
        $this->sendCallback($payload, 'ev_same')->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, MediaCallbackEvent::where('event_id', 'ev_same')->count());
    }

    public function test_callback_with_bad_signature_is_rejected(): void
    {
        $this->sendCallback(['type' => 'processing.completed', 'video_ref' => 'x', 'version' => 1, 'state' => 'ready'], 'ev_bad', 'wrong-secret')
            ->assertStatus(403);
    }

    public function test_ready_callback_for_a_purged_version_is_rejected(): void
    {
        [$lesson, $uuid] = $this->makeReadyVideo();
        $version = MediaVersion::withoutGlobalScopes()->firstOrFail();
        // Force to purged, then a stray "ready" callback must not resurrect it.
        $version->update(['state' => 'quarantined']);
        $version->update(['state' => 'purged']);

        $this->sendCallback([
            'type' => 'processing.completed', 'video_ref' => $uuid, 'version' => 1, 'state' => 'ready', 'playback_id' => 'pb_1',
        ])->assertStatus(409);
    }

    public function test_student_playback_authorization_issues_a_bound_token(): void
    {
        [$lesson, $uuid, $ver] = $this->makeReadyVideo();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $res = $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/media/remote/lessons/{$lesson->id}/playback")
            ->assertOk()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.thumbnail_url', 'https://media.test/thumbnails/vid_1.jpg');

        $this->assertStringContainsString('media.test/v1/playback/pb_1/index.m3u8', $res->json('data.playback_url'));

        $claims = app(PlaybackTokenIssuer::class)->verify($res->json('data.token'));
        $this->assertSame('vid_1', $claims['vid']);
        $this->assertSame($ver, $claims['ver']);
        $this->assertSame($res->json('data.session'), $claims['sid']);
        $this->assertSame("u_{$student->id}", $claims['sub']);
    }

    public function test_unenrolled_student_is_denied_remote_playback(): void
    {
        [$lesson] = $this->makeReadyVideo();
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($student);
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/media/remote/lessons/{$lesson->id}/playback")
            ->assertStatus(403);
    }

    public function test_cross_tenant_version_is_not_reachable(): void
    {
        [$lesson, $uuid] = $this->makeReadyVideo();
        $version = MediaVersion::withoutGlobalScopes()->firstOrFail();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $intruder = $this->member(TenantUserRole::Teacher, $other);

        Sanctum::actingAs($intruder);
        $this->withHeaders(['X-Tenant' => 'other'])
            ->postJson("/api/v1/teacher/remote-videos/versions/{$version->id}/quarantine")
            ->assertStatus(404);
    }
}
