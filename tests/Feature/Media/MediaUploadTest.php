<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');   // media source lives on the PRIVATE disk now
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
        Sanctum::actingAs($this->teacher());
    }

    private function teacher(): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    private function lesson(): Lesson
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    public function test_direct_file_upload_stores_and_becomes_ready_and_links_lesson(): void
    {
        $lesson = $this->lesson();
        $file = UploadedFile::fake()->create('lecture.mp4', 2048, 'video/mp4');

        $res = $this->withHeaders($this->h)->post('/api/v1/teacher/media/uploads', [
            'lesson_id' => $lesson->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $res->assertJsonPath('data.media.status', MediaStatus::Ready->value);
        $this->assertNotNull($res->json('data.media.uuid'));   // id is present (no "undefined")
        $this->assertNull($res->json('data.upload'));

        // Stored on the PRIVATE disk + linked as the lesson's video.
        $asset = MediaAsset::withoutGlobalScopes()->first();
        Storage::disk('local')->assertExists($asset->source_key);
        $this->assertNotNull($lesson->fresh()->video_asset_id);
    }

    public function test_uploaded_video_is_not_directly_downloadable(): void
    {
        $file = UploadedFile::fake()->create('lecture.mp4', 512, 'video/mp4');

        $res = $this->withHeaders($this->h)->post('/api/v1/teacher/media/uploads', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        // No direct URL is exposed for a video — playback must go through the
        // token-gated encrypted-HLS flow. The old raw-file route is gone.
        $this->assertNull($res->json('data.media.url'));
        $this->assertArrayNotHasKey('media.file', app('router')->getRoutes()->getRoutesByName());

        // The source sits on the private disk, not the web-served public disk.
        $asset = MediaAsset::withoutGlobalScopes()->first();
        $this->assertStringStartsWith('media/source/', $asset->source_key);
        Storage::disk('local')->assertExists($asset->source_key);
        Storage::disk('public')->assertMissing($asset->source_key);
    }

    public function test_async_signed_upload_url_receives_raw_file_and_becomes_ready(): void
    {
        $lesson = $this->lesson();

        // 1) Start with no file → get a signed upload_url (the async/production flow).
        $start = $this->withHeaders($this->h)->postJson('/api/v1/teacher/media/uploads', [
            'lesson_id' => $lesson->id,
            'filename' => 'lecture.mp4',
        ])->assertStatus(201);

        $uploadUrl = $start->json('data.upload.upload_url');
        $uuid = $start->json('data.media.uuid');
        $this->assertNotNull($uploadUrl);
        $this->assertStringContainsString('/api/v1/media/upload/', $uploadUrl); // under api/* → CORS covers it

        // 2) PUT the raw file bytes to the signed URL — no X-Tenant / bearer, like a presigned PUT.
        $this->call('PUT', $uploadUrl, [], [], [], ['CONTENT_TYPE' => 'video/mp4', 'HTTP_ACCEPT' => 'application/json'], 'FAKE-MP4-BYTES')
            ->assertStatus(200)->assertJsonPath('data.status', MediaStatus::Ready->value);

        // 3) Asset is ready, stored on the private disk, and linked as the lesson's video.
        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $uuid)->first();
        Storage::disk('local')->assertExists($asset->source_key);
        $this->assertSame($asset->id, $lesson->fresh()->video_asset_id);

        // 4) The follow-up complete call is idempotent (stays ready).
        $this->withHeaders($this->h)->postJson("/api/v1/teacher/media/uploads/{$uuid}/complete")
            ->assertStatus(200)->assertJsonPath('data.status', MediaStatus::Ready->value);
    }

    public function test_unsigned_upload_url_is_rejected(): void
    {
        $start = $this->withHeaders($this->h)->postJson('/api/v1/teacher/media/uploads', [
            'filename' => 'lecture.mp4',
        ])->assertStatus(201);
        $uuid = $start->json('data.media.uuid');

        // Same path but without the signature → 403 (the signature is the auth).
        $this->call('PUT', "/api/v1/media/upload/{$uuid}", [], [], [], ['CONTENT_TYPE' => 'video/mp4', 'HTTP_ACCEPT' => 'application/json'], 'X')
            ->assertStatus(403);
    }

    public function test_missing_media_returns_clean_404_without_leaking_internals(): void
    {
        $res = $this->withHeaders($this->h)->getJson('/api/v1/teacher/media/undefined')->assertStatus(404);

        $res->assertJsonPath('error.code', 'not_found')->assertJsonPath('error.message', 'Resource not found.');
        $this->assertStringNotContainsString('No query results', (string) $res->json('error.message'));
    }
}
